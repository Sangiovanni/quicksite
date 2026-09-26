<?php
/**
 * Contacts — the caller's own list of people they have already looked up.
 *
 * NOT commands. The command surface is a CLI for DEVELOPING A PROJECT; keeping
 * track of who you have worked with is not project development, which is the
 * same line that put account self-service, membership self-service and the two
 * directory lookups on /admin/self (beta.11 S6).
 *
 * WHY IT EXISTS. Inviting somebody requires their EXACT public display name —
 * qs_directory_find_user is exact-match only, deliberately — and display names
 * are not unique, so the caller then picks the right account out of several by
 * its opaque id. Doing that from memory, for the same handful of collaborators,
 * on every project, is the friction this removes. It removes the TYPING, never
 * the lookup: somebody who is not already a contact is still found the same way.
 *
 * ⚠⚠ THE INVARIANT, AND HOW IT IS CONSTRUCTED RATHER THAN CHECKED.
 *
 * A contact list may only ever contain people the account holder has ALREADY
 * successfully looked up. It is a personal list, not a directory. find_user is
 * exact-match-only so it cannot harvest a roster; requestToJoin has no picker
 * because a list of joinable projects is an enumeration oracle. A contacts
 * feature that could browse, search, prefix-match or enumerate accounts would
 * reopen both.
 *
 * So this file NEVER READS THE USER REGISTRY TO ANSWER A QUESTION ABOUT SOMEONE
 * ELSE. The display name is stored at add time, from the lookup the caller had
 * just performed, and the list route replays exactly what was stored. It does
 * not resolve ids against users.php at read time, and qs_contacts_add does not
 * verify that the id it is handed exists.
 *
 * That last point is the whole design, and it is counter-intuitive, so:
 * validating the id would be an EXISTENCE ORACLE — a caller could post a guessed
 * id and read "does this account exist" out of the 200-vs-404. Resolving names
 * at read time would be the same oracle one step removed (store a guess, list,
 * see whether a name came back). Storing an unverified pair and replaying it
 * verbatim cannot leak anything, because everything the list can ever say is
 * something the caller themselves put in it. The cost is that a contact's name
 * can go stale if they rename; it refreshes the next time the caller looks them
 * up and works with them again, which is a real lookup they performed.
 *
 * Ids are still SHAPE-checked (usr_ + 32 hex) — that is a storage-integrity
 * rule, not an existence check, and it answers identically for a well-formed id
 * that exists and a well-formed id that does not.
 *
 * WHERE THE DATA LIVES. secure/management/config/users.php, on the caller's own
 * record, written through qs_users_mutate — the same writer the membership
 * status mirror uses. That file already carries each user's name and a per-user
 * projects cache, so a per-user contacts list follows the pattern that exists
 * rather than inventing a second per-user store. The shape mirrors the projects
 * cache: a map keyed by the stable id, each entry a small record.
 *
 * ⚠ users.php is the USER REGISTRY. A corrupting write locks people out, so
 * every write here validates BEFORE it mutates and the mutation callback returns
 * false — committing nothing — on anything it does not recognise.
 *
 * Must be required AFTER init.php — it depends on SECURE_FOLDER_PATH.
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';

/**
 * How many people one account may keep.
 *
 * A cap rather than no cap because this list is written into the user registry:
 * unbounded growth on a shared file is a denial-of-service against every account
 * in it, not just the one doing the writing. 200 is far beyond the collaborator
 * count the feature exists for, and the refusal is explicit rather than silent.
 */
const QS_CONTACTS_MAX = 200;

/** Display names are capped exactly where qs_user_create caps them. */
const QS_CONTACT_NAME_MAX = 200;

/**
 * Is this string shaped like a user id?
 *
 * The id is minted as 'usr_' . bin2hex(random_bytes(16)). Pinning that shape is
 * a storage rule — it keeps a malformed key out of the registry — and it is NOT
 * an existence check: it answers the same for a real id and an invented one.
 *
 * @param mixed $userId
 * @return bool
 */
function qs_contact_id_is_well_formed($userId): bool {
    return is_string($userId) && preg_match('/^usr_[a-f0-9]{32}$/D', $userId) === 1;
}

/**
 * Normalise a stored display name: valid UTF-8, control characters stripped,
 * capped. Returns null when nothing usable is left.
 *
 * @param mixed $name
 * @return string|null
 */
function qs_contact_clean_name($name): ?string {
    if (!is_string($name) || $name === '') {
        return null;
    }
    if (!mb_check_encoding($name, 'UTF-8')) {
        return null;
    }
    $name = preg_replace('/[\x00-\x1F\x7F]/', '', mb_substr(trim($name), 0, QS_CONTACT_NAME_MAX));
    return ($name === '' || $name === null) ? null : $name;
}

/**
 * Read one stored contacts map back as a clean, ordered list.
 *
 * Defensive on read as well as on write: a hand-edited or partially written
 * entry is SKIPPED rather than served, so a damaged record degrades to a shorter
 * list instead of a broken page.
 *
 * @param mixed $raw the raw `contacts` value from a users.php record
 * @return array list of {user_id, name, added}
 */
function qs_contacts_normalise($raw): array {
    if (!is_array($raw)) {
        return [];
    }
    $out = [];
    foreach ($raw as $userId => $entry) {
        if (!qs_contact_id_is_well_formed((string)$userId)) {
            continue;
        }
        if (!is_array($entry)) {
            continue;
        }
        $name = qs_contact_clean_name($entry['name'] ?? null);
        if ($name === null) {
            continue;
        }
        $out[] = [
            'user_id' => (string)$userId,
            'name'    => $name,
            'added'   => is_string($entry['added'] ?? null) ? $entry['added'] : null,
        ];
    }
    // Most recently added first: the person you just worked with is the person
    // you are most likely to name again.
    usort($out, function (array $a, array $b) {
        return strcmp((string)$b['added'], (string)$a['added']);
    });
    return $out;
}

/**
 * The caller's own contact list.
 *
 * Takes no parameters, deliberately: a route with nothing to vary is a route
 * with nothing to probe. It answers with what this account stored and cannot be
 * pointed at anyone else's list.
 *
 * @return ApiResponse contacts[] of {user_id, name, added}
 */
function qs_contacts_list(): ApiResponse {
    $user = getCurrentUser();
    if ($user === null) {
        return ApiResponse::create(401, 'auth.required')
            ->withMessage('Authentication required');
    }

    $contacts = qs_contacts_normalise($user['contacts'] ?? null);

    return ApiResponse::create(200, 'operation.success')
        ->withMessage(count($contacts) === 1 ? '1 contact' : count($contacts) . ' contacts')
        ->withData([
            'contacts' => $contacts,
            'count'    => count($contacts),
            'max'      => QS_CONTACTS_MAX,
        ]);
}

/**
 * Record one person on the caller's list.
 *
 * An UPSERT: re-adding somebody refreshes the stored display name from the
 * lookup the caller just performed, which is the only way a name here is ever
 * brought up to date.
 *
 * ⚠ Does NOT check that $params['user_id'] belongs to a real account — see the
 * file header. The pair is stored as given and replayed as given.
 *
 * @param array $params user_id, name
 * @return ApiResponse
 */
function qs_contacts_add(array $params): ApiResponse {
    $user = getCurrentUser();
    if ($user === null) {
        return ApiResponse::create(401, 'auth.required')
            ->withMessage('Authentication required');
    }
    $me = (string)($user['id'] ?? '');

    $targetId = trim((string)($params['user_id'] ?? ''));
    if ($targetId === '') {
        return ApiResponse::create(400, 'validation.missing_field')
            ->withMessage('user_id is required')
            ->withErrors(['user_id' => 'Required field']);
    }
    if (!qs_contact_id_is_well_formed($targetId)) {
        return ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage('user_id is not a user identifier')
            ->withErrors(['user_id' => 'Malformed identifier']);
    }
    if ($targetId === $me) {
        return ApiResponse::create(400, 'validation.invalid_value')
            ->withMessage('You are not one of your own contacts')
            ->withErrors(['user_id' => 'Refers to the caller']);
    }

    $name = qs_contact_clean_name($params['name'] ?? null);
    if ($name === null) {
        return ApiResponse::create(400, 'validation.missing_field')
            ->withMessage('name is required')
            ->withErrors(['name' => 'Required field']);
    }

    $full = false;
    $written = qs_users_mutate(function (array &$cfg) use ($me, $targetId, $name, &$full) {
        if (!isset($cfg['users'][$me]) || !is_array($cfg['users'][$me])) {
            return false;
        }
        $existing = $cfg['users'][$me]['contacts'] ?? [];
        if (!is_array($existing)) {
            // A record that is not a map is replaced rather than merged into:
            // merging into an unknown shape is how a registry gets corrupted.
            $existing = [];
        }
        // The cap is checked under the write lock, against the value about to be
        // written — not against a copy read before it.
        if (!isset($existing[$targetId]) && count($existing) >= QS_CONTACTS_MAX) {
            $full = true;
            return false;
        }
        $existing[$targetId] = [
            'name'  => $name,
            'added' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
        $cfg['users'][$me]['contacts'] = $existing;
        return true;
    });

    if ($full) {
        return ApiResponse::create(409, 'contacts.full')
            ->withMessage('Your contact list is full')
            ->withData(['max' => QS_CONTACTS_MAX]);
    }
    if ($written !== true) {
        return ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage('Failed to save the contact');
    }

    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Contact saved')
        ->withData([
            'user_id' => $targetId,
            'name'    => $name,
            'saved'   => true,
        ]);
}

/**
 * Drop one person from the caller's list.
 *
 * Answers the same whether the entry was there or not: this route must not
 * become a way to ask what is on somebody's list, and the caller's own list is
 * already readable through qs_contacts_list anyway.
 *
 * @param array $params user_id
 * @return ApiResponse
 */
function qs_contacts_remove(array $params): ApiResponse {
    $user = getCurrentUser();
    if ($user === null) {
        return ApiResponse::create(401, 'auth.required')
            ->withMessage('Authentication required');
    }
    $me = (string)($user['id'] ?? '');

    $targetId = trim((string)($params['user_id'] ?? ''));
    if ($targetId === '') {
        return ApiResponse::create(400, 'validation.missing_field')
            ->withMessage('user_id is required')
            ->withErrors(['user_id' => 'Required field']);
    }
    if (!qs_contact_id_is_well_formed($targetId)) {
        return ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage('user_id is not a user identifier')
            ->withErrors(['user_id' => 'Malformed identifier']);
    }

    // Separated from the callback's return value so that "there was nothing to
    // remove" does NOT rewrite the user registry. qs_users_mutate writes
    // whenever the callback returns anything but false, and a no-op rewrite of
    // this file is a risk taken for nothing.
    $nothingToDo = false;
    $written = qs_users_mutate(function (array &$cfg) use ($me, $targetId, &$nothingToDo) {
        if (!isset($cfg['users'][$me]) || !is_array($cfg['users'][$me])) {
            return false;
        }
        $existing = $cfg['users'][$me]['contacts'] ?? null;
        if (!is_array($existing) || !isset($existing[$targetId])) {
            $nothingToDo = true;
            return false;
        }
        unset($existing[$targetId]);
        $cfg['users'][$me]['contacts'] = $existing;
        return true;
    });

    if ($written !== true && !$nothingToDo) {
        return ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage('Failed to update your contacts');
    }

    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Contact removed')
        ->withData([
            'user_id' => $targetId,
            'removed' => true,
        ]);
}
