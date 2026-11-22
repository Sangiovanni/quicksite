<?php
/**
 * WorkflowManager - Manages workflow specification loading, validation, and rendering
 * 
 * This class handles:
 * - Loading workflow JSON files from the core/ directory, and the site templates of templates/
 *   that a workflow may take its steps from
 * - Validating workflows against the JSON schema
 * - Fetching data requirements via CommandRunner
 * - Rendering prompt templates with variable substitution
 * - Generating steps from forEach definitions
 * - The phases a run goes through (its pre-workflows, itself, its post-workflows), each
 *   resolved when the run reaches it, and what each step deletes or changes
 *
 * @package QuickSite\Admin
 */

require_once SECURE_FOLDER_PATH . '/src/classes/CommandRunner.php';
require_once SECURE_FOLDER_PATH . '/src/classes/RegexPatterns.php';
require_once SECURE_FOLDER_PATH . '/src/functions/languageRegistry.php';

class WorkflowManager {
    
    /** @var string Base path for workflows */
    private string $workflowsBasePath;
    
    /** @var array Cached workflows */
    private array $workflowsCache = [];

    /** @var array|null The templates folder as listTemplates() read it, once per request */
    private ?array $templatesCache = null;
    
    /** @var string Project ID */
    private string $projectId;
    
    /** @var array|null Token info for role-based permission checks (optional) */
    private ?array $tokenInfo = null;
    
    /** @var array Per-render help cache: [commandName => helpData]. Lifetime = one renderPrompt() call. */
    private array $helpCache = [];

    /** @var array Each command's category, as stepEffect() reads it: getCommandCategory() reads the categories file at every call. */
    private array $categoryCache = [];
    
    /** Max recursion depth for {{> partial}} resolution (cycle/runaway guard). */
    private const PARTIALS_MAX_DEPTH = 5;

    /** Max nesting of sub-workflows a run follows: a sub-workflow's own pre- and post-workflows, and theirs. */
    private const PHASES_MAX_DEPTH = 5;

    /** The last reference collectPhases() gave a phase: how a sub-workflow's phase finds its parent's. */
    private int $phaseRefs = 0;

    /** A command whose name begins so deletes what it names: the console's convention (admin.js isDestructiveCommand()). */
    private const DELETE_NAME = '/^(delete|remove|clear|reset|purge)/i';

    /** A command whose name begins so adds something new: a run is never warned about it. */
    private const CREATE_NAME = '/^(add|create|upload|insert|duplicate)/';

    /**
     * A property name as an answer's bound reads it: letters, digits, `-` and `_`, or a custom property
     * (--name). A name with an escape or any other character could read in a browser as one it refuses.
     */
    private const PLAIN_PROPERTY = '/^(?:--[A-Za-z0-9_-]+|-?[A-Za-z_][A-Za-z0-9_-]*)$/D';

    /** The categories whose writes change the site's content: such a write that neither deletes nor adds is warned as a change. */
    private const CONTENT_CATEGORIES = [
        'content.write', 'translation.write', 'route.write', 'asset.write',
        'interaction.write', 'privacy.manage', 'style.write',
    ];

    /**
     * What a warned step touches, by category: its group in the warning, and the parameters that may
     * name its target (the first one present). A command of no listed category is its own group.
     */
    private const EFFECT_GROUPS = [
        'route.write'       => ['pages', ['route', 'alias']],
        'asset.write'       => ['media', ['filename', 'imageName']],
        'style.write'       => ['stylesheet', ['selector', 'name']],
        'interaction.write' => ['interactions', ['pageName', 'route']],
        'privacy.manage'    => ['privacy', ['id', 'route', 'lang']],
    ];

    /**
     * Constructor
     * 
     * @param string $projectId Project identifier (optional)
     */
    public function __construct(string $projectId = '') {
        $this->projectId = $projectId;
        $this->workflowsBasePath = SECURE_FOLDER_PATH . '/admin/workflows';
    }
    
    /**
     * Set the token info for role-based permission checking.
     * When set, fetchDataRequirements will verify each command is allowed
     * for the user's role before executing via CommandRunner.
     * 
     * @param array $tokenInfo Resolved user from validateBearerToken() — must have 'id'
     */
    public function setTokenInfo(array $tokenInfo): void {
        $this->tokenInfo = $tokenInfo;
    }
    
    /**
     * Get all available workflows.
     *
     * Workflows are SHIPPED, not authored: only `core/` is read. The custom
     * workflow feature (author-written specs in `custom/`, saved + deleted
     * through the admin AJAX helper) was removed — it was an
     * unused artifact that had become a flaw vector: an ungated save arm let
     * any authenticated caller author a spec whose `dataRequirements` named
     * arbitrary CommandRunner-allowlisted commands, then execute it.
     *
     * @return array List of workflow metadata
     */
    public function listWorkflows(): array {
        $workflows = [];

        $coreDir = $this->workflowsBasePath . '/core';
        if (is_dir($coreDir)) {
            foreach (glob($coreDir . '/*.json') as $file) {
                $workflow = $this->loadWorkflow(basename($file, '.json'));
                if ($workflow) {
                    $workflow['_source'] = 'core';
                    $workflows[] = $workflow;
                }
            }
        }

        return $workflows;
    }
    
    /**
     * The groups a workflow may be listed in (meta.category), in the order the Workflows panel shows them: the schema's
     * own list, so a new group is one edit there. Read once per request.
     *
     * @return string[]
     */
    public function categories(): array {
        static $categories = null;
        if ($categories === null) {
            $schema = json_decode((string) @file_get_contents($this->workflowsBasePath . '/schema.json'), true);
            $enum = $schema['properties']['meta']['properties']['category']['enum'] ?? null;
            $categories = is_array($enum) ? array_values(array_filter($enum, 'is_string')) : [];
        }
        return $categories;
    }

    /**
     * Load a workflow by ID
     * 
     * @param string $workflowId Workflow identifier
     * @return array|null Workflow data or null if not found
     */
    public function loadWorkflow(string $workflowId): ?array {
        // A workflow id names a file of core/: letters, digits, hyphens and underscores only, so an id
        // taken from a request never reaches a file outside that folder.
        if (!RegexPatterns::match('identifier_alphanum', $workflowId)) {
            return null;
        }

        // Check cache
        if (isset($this->workflowsCache[$workflowId])) {
            return $this->workflowsCache[$workflowId];
        }
        
        // Shipped workflows only — `core/`. See listWorkflows() for why there is
        // no longer a `custom/` lookup.
        $paths = [
            $this->workflowsBasePath . '/core/' . $workflowId . '.json',
        ];

        foreach ($paths as $path) {
            if (file_exists($path)) {
                $content = file_get_contents($path);
                $workflow = json_decode($content, true);

                if ($workflow && isset($workflow['id'])) {
                    $workflow['_filePath'] = $path;
                    $workflow['_folder'] = dirname($path);
                    $workflow['_source'] = 'core';
                    $this->offerTemplates($workflow);

                    $this->workflowsCache[$workflowId] = $workflow;
                    return $workflow;
                }
            }
        }
        
        return null;
    }

    /**
     * The site templates a workflow may take its steps from: every valid file of `templates/`, by id, in the files' order.
     *
     * Templates are SHIPPED, as workflows are: one folder is read, and nothing a request names reaches a file outside
     * it (loadTemplate()). A file is a template when it is a JSON object whose `id` is its file's name, with a list of
     * languages and a list of steps that each name a command; any other file is left out and logged.
     *
     * @return array<string, array> id => the template's file as an array
     */
    public function listTemplates(): array {
        if ($this->templatesCache !== null) return $this->templatesCache;
        $this->templatesCache = [];
        $files = glob($this->workflowsBasePath . '/templates/*.json') ?: [];
        sort($files);
        foreach ($files as $file) {
            $id = basename($file, '.json');
            $text = (string) @file_get_contents($file);
            $template = json_decode($text, true);
            $why = $this->templateRefusal($id, $template);
            if ($why !== null) {
                error_log("[WorkflowManager] template '{$id}' left out: {$why}");
                continue;
            }
            // The file's hash with one kind of line ending, as the previews' tool takes it: a checkout's endings change nothing.
            $template['_hash'] = hash('sha256', str_replace("\r\n", "\n", $text));
            $this->templatesCache[$id] = $template;
        }
        return $this->templatesCache;
    }

    /** One template by its id, or null: an id is letters, digits, hyphens and underscores, and names a file listTemplates() holds. */
    public function loadTemplate(string $templateId): ?array {
        if (!RegexPatterns::match('identifier_alphanum', $templateId)) return null;
        return $this->listTemplates()[$templateId] ?? null;
    }

    /** Why a file of `templates/` is no template, or null when it is one. */
    private function templateRefusal(string $id, $template): ?string {
        if (!RegexPatterns::match('identifier_alphanum', $id)) return 'its file name is not an id';
        if (!is_array($template)) return 'it is not a JSON object';
        if (($template['id'] ?? null) !== $id) return 'its id is not its file name';
        $languages = $template['languages'] ?? null;
        if (!is_array($languages) || $languages === [] || array_filter($languages, static fn($l) => !is_string($l) || $l === '') !== []) {
            return 'it lists no languages';
        }
        $steps = $template['steps'] ?? null;
        if (!is_array($steps) || $steps === []) return 'it has no steps';
        foreach ($steps as $step) {
            if (!is_array($step) || !is_string($step['command'] ?? null) || $step['command'] === '') return 'a step names no command';
        }
        if (isset($template['data']) && !is_array($template['data'])) return 'its data is not an object';
        return null;
    }

    /**
     * What a template's file says of itself, for a picker: its id, icon, name and line (in the panel's language, from its
     * keys, else as the file writes them), its kind (an id the picker groups it under, '' when the file states none), its
     * languages, whether it holds a dark theme, the fields it asks a run for
     * (`asks`: ids of the workflow's parameters, which a field's condition reads to show itself), and its file's hash.
     */
    public function templateChoice(array $template): array {
        if (!function_exists('__workflow')) {
            require_once SECURE_FOLDER_PATH . '/admin/functions/AdminTranslation.php';
        }
        $id = (string) $template['id'];
        $text = static function (string $field) use ($template, $id): string {
            $direct = is_string($template[$field] ?? null) ? $template[$field] : '';
            $key = $template[$field . 'Key'] ?? null;
            if (!is_string($key) || $key === '') return $direct !== '' ? $direct : ($field === 'title' ? $id : '');
            return (string) __workflow($template, $key, $direct !== '' ? $direct : ($field === 'title' ? $id : ''));
        };
        return [
            'id' => $id,
            'icon' => is_string($template['icon'] ?? null) ? $template['icon'] : '',
            'title' => $text('title'),
            'description' => $text('description'),
            'kind' => is_string($template['kind'] ?? null) && RegexPatterns::match('identifier_alphanum', $template['kind']) ? $template['kind'] : '',
            'languages' => array_values($template['languages']),
            'dark' => is_array($template['data']['dark'] ?? null) && $template['data']['dark'] !== [],
            'asks' => array_values(array_filter(
                is_array($template['asks'] ?? null) ? $template['asks'] : [],
                static fn($field) => is_string($field) && RegexPatterns::match('identifier_alphanum', $field)
            )),
            'hash' => (string) ($template['_hash'] ?? ''),
            'picture' => $this->templatePicture($id),
            'preview' => $this->templatePreview($id, (string) ($template['_hash'] ?? '')),
        ];
    }

    /** The folder of the panel's own assets, or null outside a web request (a tool, a probe): no picture and no preview then. */
    private function panelAssets(): ?string {
        return defined('ADMIN_ASSET_ROOT') ? rtrim(str_replace('\\', '/', (string) ADMIN_ASSET_ROOT), '/') . '/admin/assets' : null;
    }

    /** A template's picture among the panel's images (templates/<id>.webp, .jpg or .png), as its file name, or null. */
    private function templatePicture(string $id): ?string {
        $assets = $this->panelAssets();
        if ($assets === null) return null;
        foreach (['webp', 'jpg', 'png'] as $ext) {
            if (is_file("$assets/images/templates/$id.$ext")) return "$id.$ext";
        }
        return null;
    }

    /**
     * A template's built preview, when the panel may show it: the one the previews' tool wrote for this very file (its
     * hash is the file's). A preview of a template that changed since is not shown. Whether the engine that built it is
     * today's is the tool's own check (--check), run at each release: an installed engine changes only with one.
     *
     * @return array{themes: string[], languages: string[], pages: string[], scripts: bool}|null
     */
    private function templatePreview(string $id, string $hash): ?array {
        $assets = $this->panelAssets();
        if ($assets === null || $hash === '') return null;
        $manifest = json_decode((string) @file_get_contents("$assets/template-previews/$id/preview.json"), true);
        if (!is_array($manifest) || ($manifest['template'] ?? null) !== $hash) return null;
        $list = static fn($v): array => array_values(array_filter(is_array($v) ? $v : [], 'is_string'));
        $themes = $list($manifest['themes'] ?? null);
        $pages = $list($manifest['pages'] ?? null);
        if ($themes === [] || $pages === []) return null;
        return [
            'themes' => $themes,
            'languages' => $list($manifest['languages'] ?? null),
            'pages' => $pages,
            'scripts' => !empty($manifest['scripts']),
        ];
    }

    /** A `template` parameter is given its choices as the workflow loads: every template, as templateChoice() says it. */
    private function offerTemplates(array &$workflow): void {
        if (empty($workflow['parameters']) || !is_array($workflow['parameters'])) return;
        foreach ($workflow['parameters'] as &$param) {
            if (!is_array($param) || ($param['type'] ?? null) !== 'template') continue;
            $param['templates'] = array_map([$this, 'templateChoice'], array_values($this->listTemplates()));
        }
        unset($param);
    }

    /**
     * The template a run of this workflow takes its steps from: the one its `stepsFrom` parameter names. Null for a
     * workflow with no `stepsFrom`, and for a run that names none yet. A name that is no template is refused whole
     * (an exception), so no phase of that run is resolved: not the workflows that run before its steps either.
     */
    private function chosenTemplate(array $workflow, array $userParams): ?array {
        $paramId = $workflow['stepsFrom'] ?? null;
        if (!is_string($paramId) || $paramId === '') return null;
        $chosen = $userParams[$paramId] ?? '';
        if ($chosen === '' || $chosen === null) return null;
        $template = is_string($chosen) ? $this->loadTemplate($chosen) : null;
        if ($template === null) {
            throw new InvalidArgumentException("workflow '" . ($workflow['id'] ?? '') . "': no such template");
        }
        return $template;
    }

    /** The steps a workflow's file can send: its own, and for one that takes its steps from a template, every template's. */
    private function everyStep(array $workflow): array {
        $steps = array_values(array_filter($workflow['steps'] ?? [], 'is_array'));
        if (is_string($workflow['stepsFrom'] ?? null) && $workflow['stepsFrom'] !== '') {
            foreach ($this->listTemplates() as $template) {
                foreach ($template['steps'] as $step) $steps[] = $step;
            }
        }
        return $steps;
    }
    
    /**
     * Resolve `default` template references in workflow parameters.
     *
     * Parameter authors can write `default: "{{data.X}}"` to seed an
     * initial value from a fetched dataRequirement. This walks the
     * parameter list and substitutes any such templates against the
     * provided `data` (and optional `userParams`) context.
     *
     * Templates inside literal-string defaults that are NOT a full
     * `{{path}}` placeholder fall through `resolveStepParamValue`'s
     * inline-substitution path — useful for `default: "Hello {{param.name}}"`.
     *
     * Mutates the workflow in place. Idempotent (safe to call twice).
     *
     * @param array $workflow The workflow (modified in place)
     * @param array $data     Fetched dataRequirements keyed by id
     * @param array $userParams Optional — preserved across `param.X` refs
     */
    public function resolveParameterDefaults(array &$workflow, array $data, array $userParams = []): void {
        if (empty($workflow['parameters']) || !is_array($workflow['parameters'])) return;
        $context = [
            'param'  => $userParams,
            'data'   => $data,
            'config' => defined('CONFIG') ? CONFIG : []
        ];
        foreach ($workflow['parameters'] as &$param) {
            if (!isset($param['default'])) continue;
            $default = $param['default'];
            if (!is_string($default)) continue;
            if (strpos($default, '{{') === false) continue;
            $resolved = $this->resolveStepParamValue($default, $context);
            $param['default'] = $resolved;
        }
        unset($param);
    }

    /**
     * Resolve all *Key fields in a loaded workflow into their non-Key
     * siblings using __workflow(). Walks meta + parameters + nested
     * option lists. Idempotent — existing non-Key fields stay untouched.
     *
     * Output additions:
     *   meta.title, meta.description
     *   parameters[*].label, parameters[*].help, parameters[*].placeholder
     *   parameters[*].options[*].label
     *
     * @param array $workflow The workflow definition (modified in place)
     * @return void
     */
    public static function resolveLabelsInPlace(array &$workflow): void {
        if (!function_exists('__workflow')) {
            require_once SECURE_FOLDER_PATH . '/admin/functions/AdminTranslation.php';
        }
        if (isset($workflow['meta'])) {
            $meta = &$workflow['meta'];
            if (isset($meta['titleKey']) && !isset($meta['title'])) {
                $meta['title'] = __workflow($workflow, $meta['titleKey'], $workflow['id'] ?? '');
            }
            if (isset($meta['descriptionKey']) && !isset($meta['description'])) {
                $meta['description'] = __workflow($workflow, $meta['descriptionKey'], '');
            }
            unset($meta);
        }
        if (!empty($workflow['parameters']) && is_array($workflow['parameters'])) {
            foreach ($workflow['parameters'] as &$param) {
                if (isset($param['labelKey']) && !isset($param['label'])) {
                    $param['label'] = __workflow($workflow, $param['labelKey'], $param['id'] ?? '');
                }
                if (isset($param['helpKey']) && !isset($param['help'])) {
                    $param['help'] = __workflow($workflow, $param['helpKey'], '');
                }
                if (isset($param['placeholderKey']) && !isset($param['placeholder'])) {
                    $param['placeholder'] = __workflow($workflow, $param['placeholderKey'], '');
                }
                if (!empty($param['options']) && is_array($param['options'])) {
                    foreach ($param['options'] as &$opt) {
                        if (isset($opt['labelKey']) && !isset($opt['label'])) {
                            $opt['label'] = __workflow($workflow, $opt['labelKey'], $opt['value'] ?? '');
                        }
                    }
                    unset($opt);
                }
            }
            unset($param);
        }
    }

    /**
     * Fetch all data requirements for a workflow
     *
     * @param array $workflow The workflow definition
     * @param array $userParams User-provided parameters (for condition evaluation)
     * @return array Fetched data keyed by requirement ID
     */
    public function fetchDataRequirements(array $workflow, array $userParams = []): array {
        $data = [];
        
        if (!isset($workflow['dataRequirements'])) {
            return $data;
        }
        
        foreach ($workflow['dataRequirements'] as $req) {
            $id = $req['id'];
            
            // Check condition if present
            if (isset($req['condition'])) {
                $condition = $req['condition'];
                $conditionMet = $this->evaluateDataCondition($condition, $userParams);
                
                if (!$conditionMet) {
                    // Skip this data requirement
                    $data[$id] = null;
                    continue;
                }
            }
            
            $command = $req['command'];
            $params = $req['params'] ?? [];
            $urlParams = $req['urlParams'] ?? [];
            $extract = $req['extract'] ?? null;
            
            // Role-based permission check: if tokenInfo is set, verify the user can run this command.
            // hasPermission is project-scoped for project commands; authorize against the loaded
            // project context (PROJECT_NAME). WorkflowManager always runs inside a resolved project
            // (admin/api or the dispatcher), so a workflow's data commands are checked against it.
            if ($this->tokenInfo !== null && function_exists('hasPermission')) {
                if (!hasPermission($this->tokenInfo, $command, defined('PROJECT_NAME') ? PROJECT_NAME : null)) {
                    $data[$id] = [
                        '_error' => true,
                        '_message' => "Permission denied for command: {$command}",
                        '_command' => $command
                    ];
                    continue;
                }
            }
            
            // Replace placeholders in urlParams with actual user param values
            $urlParams = array_map(function($param) use ($userParams) {
                if (preg_match('/^\{\{(\w+)\}\}$/', $param, $matches)) {
                    return $userParams[$matches[1]] ?? $param;
                }
                return $param;
            }, $urlParams);
            
            try {
                if ($extract) {
                    $data[$id] = CommandRunner::extractData($command, $params, $urlParams, $extract);
                } else {
                    $response = CommandRunner::execute($command, $params, $urlParams);
                    $data[$id] = $response->getData();
                }
            } catch (Exception $e) {
                // Store error info for debugging
                $data[$id] = [
                    '_error' => true,
                    '_message' => $e->getMessage(),
                    '_command' => $command
                ];
            }
        }
        
        return $data;
    }
    
    /**
     * Generate one workflow's own steps, expanded: forEach, conditions and parameter resolution.
     * Its pre- and post-workflows are phases of their own (getWorkflowPhases()).
     *
     * @param array $workflow The workflow definition
     * @param array $userParams User-provided parameters
     * @param array $fetchedData Data from dataRequirements
     * @param array $configData Project configuration data
     * @return array Expanded list of commands ready for execution
     */
    public function generateSteps(array $workflow, array $userParams = [], array $fetchedData = [], array $configData = []): array {
        $expandedSteps = [];

        // A workflow that takes its steps from a template runs the chosen template's, with the template's own data
        // (its texts, its dark theme) as static data.
        $template = $this->chosenTemplate($workflow, $userParams);
        if ($template !== null) {
            $workflow['steps'] = $template['steps'];
            $workflow['staticData'] = array_merge(
                is_array($template['data'] ?? null) ? $template['data'] : [],
                ['template' => ['id' => $template['id'], 'languages' => array_values($template['languages'])]],
                is_array($workflow['staticData'] ?? null) ? $workflow['staticData'] : []
            );
        }

        // Merge static data defined in the workflow itself
        if (!empty($workflow['staticData']) && is_array($workflow['staticData'])) {
            $fetchedData = array_merge($workflow['staticData'], $fetchedData);
        }

        $context = [
            'param' => $userParams,
            'data' => $fetchedData,
            'config' => $configData
        ];

        // Process main workflow steps
        if (isset($workflow['steps']) && !empty($workflow['steps'])) {
            foreach ($workflow['steps'] as $step) {
                // Check step condition first
                if (isset($step['condition'])) {
                    if (!$this->evaluateStepCondition($step['condition'], $context)) {
                        continue; // Skip this step
                    }
                }
                
                // Handle forEach expansion
                if (isset($step['forEach'])) {
                    $expanded = $this->expandForEach($step, $context);
                    foreach ($expanded as $expandedStep) {
                        $expandedSteps[] = $expandedStep;
                    }
                } else {
                    // Single step - resolve params
                    $resolvedParams = $this->resolveStepParams($step['params'] ?? [], $context);
                    $resolvedParams = $this->resolveEachLoops($resolvedParams, $context);
                    $resolvedStep = [
                        'command' => $step['command'],
                        'method' => $step['method'] ?? 'POST',
                        'params' => $resolvedParams
                    ];
                    
                    // Carry over execution control properties
                    foreach (['abortOnFail', 'retryOn', 'maxRetries', 'retryDelayMs'] as $prop) {
                        if (isset($step[$prop])) {
                            $resolvedStep[$prop] = $step[$prop];
                        }
                    }
                    
                    $expandedSteps[] = $resolvedStep;
                }
            }
        }

        return $expandedSteps;
    }
    
    /**
     * The phases a run of this workflow goes through, in order: each pre-workflow, the workflow itself,
     * then each post-workflow; a sub-workflow's own pre- and post-workflows run around it, depth first.
     * A sub-workflow's condition is evaluated at every walk — when the run is planned, and again for
     * each phase the run reaches (resolvePhase()) — against the parameters its parent was given, and
     * the parent's data when the condition reads any. An entry with a forEach (and its filter, read as
     * a step's) is one phase per item, {{$value}} in its params and its label. Each phase carries its
     * key (the entries that lead to it, and a repeated entry's item), so a phase that is no longer the
     * one the plan named at its place is refused rather than run (the workflow-generate-steps arm).
     * Nothing else is resolved: a phase names its workflow and the parameters its parent passes it. A
     * workflow already on the way to itself (a cycle), or nested deeper than PHASES_MAX_DEPTH, is left
     * out.
     *
     * @param array $workflow The workflow definition
     * @param array $userParams The run's parameters
     * @return array List of ['kind' => 'pre'|'main'|'post', 'workflowId' => string, 'key' => string,
     *               'params' => array, 'requestFrom' => int|null (settleRequests()),
     *               'label'? => string (a repeated entry's item)]
     */
    public function getWorkflowPhases(array $workflow, array $userParams = []): array {
        // A run that takes its steps from a template has no phase until one is chosen, and none at all for a name
        // that is no template (chosenTemplate() refuses it): Fresh Start never runs ahead of steps that do not exist.
        if (is_string($workflow['stepsFrom'] ?? null) && $workflow['stepsFrom'] !== ''
            && $this->chosenTemplate($workflow, $userParams) === null) {
            return [];
        }
        $phases = [];
        $this->collectPhases($workflow, $userParams, 'main', [], $phases, []);
        return $this->settleRequests($phases);
    }

    /**
     * Every workflow a run of this one may reach — itself, then each sub-workflow at any depth, each
     * once — through getWorkflowPhases()' walk taking every entry: no condition decided (a conditional
     * sub-workflow may run), no entry repeated, nothing fetched.
     *
     * @return array The workflow definitions, the given one first
     */
    public function reachableWorkflows(array $workflow): array {
        $phases = [];
        $this->collectPhases($workflow, [], 'main', [], $phases, ['every' => true]);
        $out = [];
        foreach ($phases as $phase) {
            $id = $phase['workflowId'];
            if (isset($out[$id])) continue;
            $loaded = $id === (string) ($workflow['id'] ?? '') ? $workflow : $this->loadWorkflow($id);
            if ($loaded) $out[$id] = $loaded;
        }
        return array_values($out);
    }

    /**
     * The commands a run of this workflow may send, for a role check: the related commands and the
     * steps' commands of every workflow it may reach (reachableWorkflows()); for one that takes its
     * steps from a template, every template's (everyStep()).
     */
    public function runCommands(array $workflow): array {
        $commands = [];
        foreach ($this->reachableWorkflows($workflow) as $reached) {
            foreach ($this->relatedCommands($reached) as $command) $commands[] = $command;
            foreach ($this->everyStep($reached) as $step) {
                if (is_string($step['command'] ?? null) && $step['command'] !== '') $commands[] = $step['command'];
            }
        }
        return array_values(array_unique($commands));
    }

    /**
     * The commands a workflow names in relatedCommands: an entry is a command's name, or
     * {"command": name, "condition": expression}, read as a data requirement's condition is.
     * With no parameters, every command named, whatever its condition: what a run CAN send (the role
     * check, the row's facts). With a run's parameters, those whose condition holds for them: what the
     * prompt teaches and what an answer may send. Anything else in the list is left out.
     */
    public function relatedCommands(array $workflow, ?array $params = null): array {
        $commands = [];
        foreach (($workflow['relatedCommands'] ?? []) as $entry) {
            if (is_string($entry)) {
                if ($entry !== '') $commands[] = $entry;
                continue;
            }
            if (!is_array($entry) || !is_string($entry['command'] ?? null) || $entry['command'] === '') continue;
            $condition = $entry['condition'] ?? null;
            if ($params !== null && is_string($condition) && $condition !== '' && !$this->evaluateDataCondition($condition, $params)) continue;
            $commands[] = $entry['command'];
        }
        return $commands;
    }

    /** The workflow as one run with these parameters reads it: its relatedCommands those whose condition holds. */
    private function withCommandsFor(array $workflow, array $params): array {
        if (isset($workflow['relatedCommands'])) $workflow['relatedCommands'] = $this->relatedCommands($workflow, $params);
        return $workflow;
    }

    /**
     * What a run of this workflow does, read from its file and every workflow it may reach (reachableWorkflows()), for the
     * words of its row in the Workflows panel. Nothing is fetched and the project is not read, so each fact says what a run
     * CAN do: a step repeated over a project's pages deletes nothing on a project with none.
     *   'ai'         a model is asked: a workflow of its reach has a promptTemplate and is not pasteOnly
     *   'paste'      the commands of an AI phase are pasted by hand (pasteOnly): no model is asked for them
     *   'requests'   the AI requests a run sends: 0, 1, or 2 for several (two AI workflows in reach, or one a forEach repeats)
     *   'effects'    each [effect, what, command] a step can have, as the run's review words it (stepEffect()): every step
     *                as its file writes it, and every command an AI answer may send (relatedCommands), once each
     *   'anyCommand' an AI answer may send any command (answerRules.anyCommand): what it changes is not known before
     *   'answerLength', 'needsKey'  the file's own meta, when set
     *
     * @return array{ai: bool, paste: bool, requests: int, effects: array, anyCommand: bool, answerLength: ?string, needsKey: ?string}
     */
    public function rowFacts(array $workflow): array {
        $paste = false;
        $any = false;
        $effects = [];
        foreach ($this->reachableWorkflows($workflow) as $reached) {
            if ($this->isAiWorkflow($reached)) {
                if (!empty($reached['pasteOnly'])) $paste = true;
                if (!empty($reached['answerRules']['anyCommand'])) $any = true;
                $steps = array_map(static fn($command) => ['command' => $command], $this->relatedCommands($reached));
            } else {
                $steps = $this->everyStep($reached);
            }
            foreach ($steps as $step) {
                $effect = $this->stepEffect($step);
                if ($effect === null) continue;
                $key = $effect['effect'] . '|' . $effect['what'] . '|' . $effect['command'];
                $effects[$key] = ['effect' => $effect['effect'], 'what' => $effect['what'], 'command' => $effect['command']];
            }
        }
        $requests = min($this->aiRequests($workflow, []), 2);
        $meta = is_array($workflow['meta'] ?? null) ? $workflow['meta'] : [];
        return [
            'ai' => $requests > 0,
            'paste' => $paste,
            'requests' => $requests,
            'effects' => array_values($effects),
            'anyCommand' => $any,
            'answerLength' => in_array($meta['answerLength'] ?? null, ['short', 'long'], true) ? $meta['answerLength'] : null,
            'needsKey' => is_string($meta['needsKey'] ?? null) && $meta['needsKey'] !== '' ? $meta['needsKey'] : null,
        ];
    }

    /**
     * How many AI requests a run of this workflow sends at most, counting its pre- and post-workflows at any depth as
     * collectPhases() follows them: one per AI workflow that is not pasteOnly, two for one that a forEach repeats (it runs once
     * per item). A conditional entry counts, as it may run; a cycle or a nesting past PHASES_MAX_DEPTH stops the count.
     */
    private function aiRequests(array $workflow, array $trail): int {
        $id = (string) ($workflow['id'] ?? '');
        if (in_array($id, $trail, true) || count($trail) >= self::PHASES_MAX_DEPTH) return 0;
        $trail[] = $id;
        $count = $this->isAiWorkflow($workflow) && empty($workflow['pasteOnly']) ? 1 : 0;
        foreach (['preWorkflows', 'postWorkflows'] as $list) {
            foreach (($workflow[$list] ?? []) as $entry) {
                $subId = is_string($entry) ? $entry : (is_array($entry) ? (string) ($entry['id'] ?? '') : '');
                $sub = $subId !== '' ? $this->loadWorkflow($subId) : null;
                if (!$sub) continue;
                $n = $this->aiRequests($sub, $trail);
                if ($n > 0 && is_array($entry) && is_string($entry['forEach'] ?? null)) $n = max($n, 2);
                $count += $n;
            }
        }
        return $count;
    }

    /**
     * One workflow's phases, added to $phases: its pre-workflows', its own, its post-workflows'. $from:
     * 'every' (reachableWorkflows()' mode), and from the entry that named it 'parentRef' (its parent's
     * phase), 'requestParent' (the entry's "request": "parent"), 'label' and 'key' (the phase's key;
     * the run's own workflow is keyed by its id).
     */
    private function collectPhases(array $workflow, array $params, string $kind, array $trail, array &$phases, array $from): void {
        $id = (string) ($workflow['id'] ?? '');
        if (in_array($id, $trail, true) || count($trail) >= self::PHASES_MAX_DEPTH) {
            error_log("[WorkflowManager] sub-workflow '{$id}' left out of the run: a cycle, or nested too deep");
            return;
        }
        $trail[] = $id;
        $data = null;
        $ref = ++$this->phaseRefs;
        $key = (string) ($from['key'] ?? $id);
        $own = ['kind' => $kind, 'workflowId' => $id, 'key' => $key, 'params' => $params, 'ref' => $ref]
            + array_intersect_key($from, ['parentRef' => true, 'requestParent' => true, 'label' => true]);
        // Every phase before the run's own workflow is a pre phase, every phase after it a post phase.
        $this->collectSubPhases($workflow, $params, 'preWorkflows', $kind === 'main' ? 'pre' : $kind, $trail, $phases, $data, $from, $ref, $key);
        $phases[] = $own;
        $this->collectSubPhases($workflow, $params, 'postWorkflows', $kind === 'main' ? 'post' : $kind, $trail, $phases, $data, $from, $ref, $key);
    }

    /**
     * The phases of one list of sub-workflows (preWorkflows or postWorkflows): each entry's condition
     * checked and its parameters resolved against its parent's — once per item of its forEach, when it
     * has one; the parent's data fetched once, and only when an entry reads it. A phase's key is its
     * parent's, then "/pre<position>:<id>" or "/post<position>:<id>" (the entry's position in its list,
     * so a sibling left out changes no other key), then "=<item>" for each item of a forEach.
     */
    private function collectSubPhases(array $workflow, array $params, string $list, string $kind, array $trail, array &$phases, ?array &$data, array $from, int $parentRef, string $parentKey): void {
        $every = !empty($from['every']);
        foreach (array_values($workflow[$list] ?? []) as $position => $entry) {
            $subId = is_string($entry) ? $entry : (is_array($entry) ? (string) ($entry['id'] ?? '') : '');
            if ($subId === '') continue;
            $entryKey = $parentKey . '/' . ($list === 'preWorkflows' ? 'pre' : 'post') . $position . ':' . $subId;
            $link = ['every' => $every, 'parentRef' => $parentRef, 'key' => $entryKey,
                'requestParent' => is_array($entry) && ($entry['request'] ?? null) === 'parent'];
            if ($every) {
                $sub = $this->loadWorkflow($subId);
                if ($sub) $this->collectPhases($sub, [], $kind, $trail, $phases, $link);
                continue;
            }
            $subParams = is_array($entry) && is_array($entry['params'] ?? null) ? $entry['params'] : [];
            $condition = is_array($entry) && is_string($entry['condition'] ?? null) ? $entry['condition'] : null;
            $forEach = is_array($entry) && is_string($entry['forEach'] ?? null) ? $entry['forEach'] : null;
            $filter = is_array($entry) && is_string($entry['filter'] ?? null) ? $entry['filter'] : null;
            $label = is_array($entry) && is_string($entry['label'] ?? null) ? $entry['label'] : null;
            if ($data === null && $this->entryReadsData($condition, $subParams, $forEach, $filter)) {
                $data = $this->fetchDataRequirements($workflow, $params);
                if (!empty($workflow['staticData']) && is_array($workflow['staticData'])) {
                    $data = array_merge($workflow['staticData'], $data);
                }
            }
            $context = ['param' => $params, 'data' => $data ?? [], 'config' => []];
            if ($condition !== null && !$this->evaluateStepCondition($condition, $context)) continue;
            $sub = $this->loadWorkflow($subId);
            if (!$sub) continue;
            if ($forEach === null) {
                $this->collectPhases($sub, $this->resolveStepParams($subParams, $context), $kind, $trail, $phases, $link);
                continue;
            }
            foreach ($this->forEachItems($forEach, $filter, $context) as $key => $value) {
                $itemContext = array_merge($context, ['$key' => $key, '$value' => $value, '$item' => $value]);
                $itemLink = $link;
                $itemLink['key'] = $entryKey . '=' . (is_string($value) ? $value
                    : (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                if ($label !== null) {
                    $itemLink['label'] = (string) $this->resolveStepParamValue($label, $itemContext);
                }
                $this->collectPhases($sub, $this->resolveStepParams($subParams, $itemContext), $kind, $trail, $phases, $itemLink);
            }
        }
    }

    /**
     * Whether a sub-workflow entry reads its parent's data: a path in its condition, its forEach or its
     * filter, or a placeholder in its parameters, that is not a param.x (a system placeholder {{__x}} and
     * a loop's {{$value}} are not).
     */
    private function entryReadsData(?string $condition, array $subParams, ?string $forEach = null, ?string $filter = null): bool {
        $paths = $forEach !== null ? [$forEach] : [];
        foreach ([$condition, $filter] as $expression) {
            if ($expression === null) continue;
            preg_match_all('/(?<![\w$])[A-Za-z_]\w*(?:\.\w+)*/', (string) preg_replace('/(["\']).*?\1/', '', $expression), $m);
            $paths = array_merge($paths, $m[0]);
        }
        preg_match_all('/\{\{\s*([^{}|\s]+)/', (string) json_encode($subParams), $m);
        foreach (array_merge($paths, $m[1]) as $path) {
            if (in_array($path, ['true', 'false', 'null', 'in', 'not_in'], true) || str_starts_with($path, '__') || str_starts_with($path, '$')) continue;
            if (!str_starts_with($path, 'param.')) return true;
        }
        return false;
    }

    /**
     * Whose "Your prompt" each phase takes, as 'requestFrom': an AI phase whose entry says
     * "request": "parent" takes its parent's phase's when that is an AI one (and the one that phase
     * takes, when it takes its own parent's); every other phase null — an AI phase its own, a phase of
     * steps none. The walk's working keys are dropped.
     */
    private function settleRequests(array $phases): array {
        $index = [];
        foreach ($phases as $i => $phase) $index[$phase['ref']] = $i;
        $isAi = function (int $i) use ($phases): bool {
            return $this->isAiWorkflow($this->loadWorkflow($phases[$i]['workflowId']) ?? []);
        };
        $owner = function (int $i) use (&$owner, $phases, $index, $isAi): ?int {
            $phase = $phases[$i];
            if (empty($phase['requestParent']) || !isset($phase['parentRef'], $index[$phase['parentRef']])) return null;
            $parent = $index[$phase['parentRef']];
            if (!$isAi($parent)) return null;
            return $owner($parent) ?? $parent;
        };
        $settled = [];
        foreach ($phases as $i => $phase) {
            $phase['requestFrom'] = $isAi($i) ? $owner($i) : null;
            unset($phase['ref'], $phase['parentRef'], $phase['requestParent']);
            $settled[] = $phase;
        }
        return $settled;
    }

    /**
     * The run's plan: every phase, each resolved against the project as it is now (resolvePhase()). A
     * later phase is resolved again when the run reaches it, since an earlier one can change what it reads.
     *
     * @return array The resolved phases, in order
     */
    public function planPhases(array $workflow, array $userParams = []): array {
        $plan = [];
        foreach ($this->getWorkflowPhases($workflow, $userParams) as $index => $phase) {
            $plan[] = $this->resolvePhaseEntry($phase, $index, []);
        }
        return $plan;
    }

    /**
     * One phase of a run, resolved now: called when the run reaches it, so it reads the project as the
     * earlier phases left it. Its parameters are what its parent passes, then its own defaults (a default
     * that reads its data, {{data.x}}, resolved against that data). A workflow with steps answers them
     * expanded. An AI workflow answers its prompt when $options['prompt'] is set (the errors of its spec
     * instead when it is not valid), and the commands its AI answered as its steps when $options['answer']
     * holds them. Each step comes with what it deletes or changes, in 'effects' (stepEffect(), null for a
     * step that is not warned). $options['key'], the phase's key as the runner's plan has it, is checked
     * first: when the walk now puts another phase at $index, or none, the answer is ['planChanged' => true]
     * and nothing is resolved — a condition or a forEach that reads the project's data planned another run.
     *
     * @return array|null ['index', 'kind', 'workflowId', 'key', 'title', 'icon', 'ai', 'requestFrom', 'steps',
     *                    'count', 'effects', 'prompt'?, 'validationErrors'?, 'refused'?], ['planChanged' => true], or null
     *                    when the run has no such phase (and no key was given)
     */
    public function resolvePhase(array $workflow, array $userParams, int $index, array $options = []): ?array {
        $phases = $this->getWorkflowPhases($workflow, $userParams);
        if (array_key_exists('key', $options) && ($phases[$index]['key'] ?? null) !== $options['key']) {
            return ['planChanged' => true];
        }
        return isset($phases[$index]) ? $this->resolvePhaseEntry($phases[$index], $index, $options) : null;
    }

    private function resolvePhaseEntry(array $phase, int $index, array $options): array {
        $workflow = $this->loadWorkflow($phase['workflowId']) ?? [];
        $ai = $this->isAiWorkflow($workflow);
        $title = $this->workflowTitle($workflow);
        $resolved = [
            'index' => $index,
            'kind' => $phase['kind'],
            'workflowId' => $phase['workflowId'],
            'key' => (string) ($phase['key'] ?? ''),
            // A repeated entry's phase is named by its item: "Translate Site — Français".
            'title' => ($phase['label'] ?? '') !== '' ? $title . ' — ' . $phase['label'] : $title,
            'icon' => (string) ($workflow['meta']['icon'] ?? ''),
            'ai' => $ai,
            'requestFrom' => $phase['requestFrom'] ?? null,
        ];
        $steps = [];
        // The phase's parameters: what its parent passed, the workflow's own defaults for the rest.
        $params = $phase['params'];
        $fromDefaults = [];
        foreach (($workflow['parameters'] ?? []) as $param) {
            $id = $param['id'] ?? null;
            if (!is_string($id) || array_key_exists($id, $params) || !array_key_exists('default', $param)) continue;
            $params[$id] = $param['default'];
            $fromDefaults[] = $id;
        }
        if ($ai && isset($options['answer']) && is_array($options['answer'])) {
            // An answer may send what the prompt taught for these parameters (relatedCommands()).
            [$steps, $resolved['refused']] = $this->answerSteps($options['answer'], $this->withCommandsFor($workflow, $params));
        }
        // An AI phase reads its data only for its prompt.
        if (!$ai || !empty($options['prompt'])) {
            $data = $this->fetchDataRequirements($workflow, $params);
            // Only the workflow's own defaults are templates: a value its parent passed is a value.
            $context = ['param' => $params, 'data' => $data, 'config' => []];
            foreach ($fromDefaults as $id) {
                if (is_string($params[$id]) && strpos($params[$id], '{{') !== false) {
                    $params[$id] = $this->resolveStepParamValue($params[$id], $context);
                }
            }
            if (!$ai) {
                $steps = $this->generateSteps($workflow, $params, $data, []);
            } else {
                $validation = $this->validateWorkflow($workflow);
                if ($validation['valid']) {
                    $resolved['prompt'] = $this->renderPrompt($workflow, $params, $data);
                } else {
                    $resolved['validationErrors'] = $validation['errors'];
                }
            }
        }
        $resolved['steps'] = $steps;
        $resolved['count'] = count($steps);
        $resolved['effects'] = array_map([$this, 'stepEffect'], $steps);
        return $resolved;
    }

    /** A workflow's title in the panel's language: its meta name, else its title key's translation, else its id. */
    private function workflowTitle(array $workflow): string {
        $labelled = $workflow;
        self::resolveLabelsInPlace($labelled);
        return (string) ($labelled['meta']['name'] ?? $labelled['meta']['title'] ?? $workflow['id'] ?? '');
    }

    /**
     * The commands an AI answered, as a phase's steps: each entry that names a command, with its method,
     * its parameters and the execution options the runner honours; anything else is left out. A step its
     * workflow does not allow never becomes a step: it goes on the list of what was refused (answerRefusal()).
     *
     * @return array [the steps, the refusals]
     */
    private function answerSteps(array $answer, array $workflow): array {
        $steps = [];
        $refused = [];
        foreach ($answer as $entry) {
            if (!is_array($entry)) continue;
            $command = $entry['command'] ?? $entry['name'] ?? null;
            if (!is_string($command) || $command === '') continue;
            $step = [
                'command' => $command,
                'method' => is_string($entry['method'] ?? null) ? $entry['method'] : 'POST',
                'params' => is_array($entry['params'] ?? null) ? $entry['params'] : [],
            ];
            foreach (['abortOnFail', 'retryOn', 'maxRetries', 'retryDelayMs'] as $prop) {
                if (isset($entry[$prop])) {
                    $step[$prop] = $entry[$prop];
                }
            }
            $refusal = $this->answerRefusal($step, $workflow);
            if ($refusal !== null) {
                $refused[] = $refusal;
                continue;
            }
            $steps[] = $step;
        }
        return [$steps, $refused];
    }

    /**
     * Why a workflow does not let its AI answer hold this step, or null when it may. An answer sends only
     * the commands its workflow names in relatedCommands for the run's parameters (the caller passes the workflow
     * as withCommandsFor() reads it), unless answerRules.anyCommand opens that. Where
     * a step names a CSS selector, answerRules.selectorStarts holds it to the elements the workflow is about,
     * and answerRules.forbiddenProperties keeps it from declaring what the workflow must not change, or a
     * property whose name the bound cannot read plainly (PLAIN_PROPERTY).
     *
     * @return array|null ['reason' => 'command'|'selector'|'property', 'command' => the step's, 'target' => what was refused]
     */
    private function answerRefusal(array $step, array $workflow): ?array {
        $rules = is_array($workflow['answerRules'] ?? null) ? $workflow['answerRules'] : [];
        $command = $step['command'];
        if (empty($rules['anyCommand']) && !in_array($command, $workflow['relatedCommands'] ?? [], true)) {
            return ['reason' => 'command', 'command' => $command, 'target' => $command];
        }
        $params = $step['params'];
        if (!empty($rules['selectorStarts']) && array_key_exists('selector', $params)) {
            $selector = $params['selector'];
            if (!is_string($selector) || !$this->selectorStaysWithin($selector, $rules['selectorStarts'])) {
                return ['reason' => 'selector', 'command' => $command, 'target' => is_string($selector) ? $selector : ''];
            }
        }
        if (!empty($rules['forbiddenProperties'])) {
            $forbidden = array_map(static fn($name) => strtolower((string) $name), $rules['forbiddenProperties']);
            foreach ($this->declaredProperties($params['styles'] ?? null) as $name) {
                $plain = preg_match(self::PLAIN_PROPERTY, $name) === 1;
                if (!$plain || in_array(strtolower($name), $forbidden, true)) {
                    return ['reason' => 'property', 'command' => $command, 'target' => $plain ? strtolower($name) : $name];
                }
            }
        }
        return null;
    }

    /**
     * Whether every selector of a CSS selector list begins with one of the given starts, as a whole name,
     * and stays inside it: `#a b` and `#a > b` begin with `#a`, `#a-b` does not, and `#a + b` or `#a ~ b`
     * select what follows `#a`, not what is in it. A comma, a `+` or a `~` inside a string, brackets or
     * parentheses is not the list's (selectorTopLevel()). A selector with an escape or a comment is refused:
     * a browser reads those as other characters, and this reads the text as it is written.
     */
    private function selectorStaysWithin(string $selectorList, array $starts): bool {
        if (str_contains($selectorList, '\\') || str_contains($selectorList, '/*')) return false;
        $top = $this->selectorTopLevel($selectorList);
        if ($top === null) return false;
        $length = strlen($selectorList);
        $from = 0;
        for ($i = 0; $i <= $length; $i++) {
            if ($i < $length && !($selectorList[$i] === ',' && $top[$i])) continue;
            if (!$this->selectorPartStaysWithin($selectorList, $top, $from, $i, $starts)) return false;
            $from = $i + 1;
        }
        return true;
    }

    /**
     * One selector of a list, its bytes from $from up to $to, as selectorStaysWithin() reads it: it begins
     * with a start as a whole name (a name goes on with a letter, a digit, `-`, `_` or any byte of a
     * non-ASCII character), and the start's compound is not followed by `+` or `~`.
     */
    private function selectorPartStaysWithin(string $list, array $top, int $from, int $to, array $starts): bool {
        $space = " \t\n\r\f";
        while ($from < $to && str_contains($space, $list[$from])) $from++;
        foreach ($starts as $start) {
            $start = (string) $start;
            $end = $from + strlen($start);
            if ($start === '' || $end > $to || substr_compare($list, $start, $from, strlen($start)) !== 0) continue;
            if ($end < $to && (ctype_alnum($list[$end]) || $list[$end] === '-' || $list[$end] === '_' || ord($list[$end]) >= 0x80)) continue;
            $i = $end;
            while ($i < $to && !($top[$i] && str_contains($space . '>+~', $list[$i]))) $i++;
            while ($i < $to && str_contains($space, $list[$i])) $i++;
            return $i >= $to || ($list[$i] !== '+' && $list[$i] !== '~');
        }
        return false;
    }

    /**
     * Which bytes of a selector stand outside every string, bracket and parenthesis: one flag per byte.
     * Null when one is left open, a bracket is closed by the other kind, or a string runs onto a new line,
     * where a browser and this reading could part ways.
     */
    private function selectorTopLevel(string $selector): ?array {
        $top = [];
        $open = [];
        $quote = null;
        $length = strlen($selector);
        for ($i = 0; $i < $length; $i++) {
            $char = $selector[$i];
            if ($quote !== null) {
                $top[$i] = false;
                if ($char === "\n" || $char === "\r" || $char === "\f") return null;
                if ($char === $quote) $quote = null;
                continue;
            }
            $top[$i] = $open === [];
            if ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '(' || $char === '[') {
                $open[] = $char === '(' ? ')' : ']';
            } elseif ($char === ')' || $char === ']') {
                if (array_pop($open) !== $char) return null;
            }
        }
        return $quote === null && $open === [] ? $top : null;
    }

    /**
     * The property names a command's styles declare, as written. An object is read as setStyleRule writes
     * it, `name: value;` for each entry, so a value holding a `;` declares what follows it too. The text is
     * cut at every `;`, one in a string included, so no declaration hides inside another, and a whole
     * comment in a name is dropped. The caller lowers the case and checks each against PLAIN_PROPERTY.
     */
    private function declaredProperties($styles): array {
        if (is_array($styles)) {
            $text = '';
            foreach ($styles as $name => $value) {
                $text .= $name . ': ' . (is_scalar($value) ? (string) $value : '') . ';';
            }
            $styles = $text;
        }
        if (!is_string($styles)) return [];
        $names = [];
        foreach (explode(';', $styles) as $declaration) {
            $colon = strpos($declaration, ':');
            if ($colon === false) continue;
            $names[] = trim((string) preg_replace('#/\*.*?\*/#s', '', substr($declaration, 0, $colon)));
        }
        return $names;
    }

    /**
     * What one step does to the project, for the run's warning: null when it only reads (a command the
     * write rate never counts) or adds something new (a name beginning add, create, upload, insert or
     * duplicate); else ['effect' => 'delete'|'change', 'what' => its group, 'target' => what it names,
     * 'command' => its command]. A delete: a name beginning delete, remove, clear, reset or purge (the
     * console's convention), a command whose help says it is destructive, editStructure with action delete
     * (a node), or editStructure of a component with an empty structure (the component itself). A change:
     * any other write of a content category (CONTENT_CATEGORIES). A write of another category that does
     * not delete is not warned.
     */
    public function stepEffect(array $step): ?array {
        $command = (string) ($step['command'] ?? $step['name'] ?? '');
        if ($command === '') return null;
        $params = is_array($step['params'] ?? null) ? $step['params'] : [];
        if (!function_exists('qs_write_rate_counts')) {
            require_once SECURE_FOLDER_PATH . '/src/functions/quota.php';
        }
        if (!function_exists('getCommandCategory')) {
            require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';
        }
        if (!qs_write_rate_counts($command)) return null;
        $category = $this->categoryCache[$command] ??= (string) getCommandCategory($command);
        $deletes = preg_match(self::DELETE_NAME, $command) === 1
            || ($command === 'editStructure' && ($params['action'] ?? null) === 'delete')
            || ($command === 'editStructure' && ($params['type'] ?? null) === 'component' && ($params['structure'] ?? null) === [])
            || !empty($this->resolveHelpForCommand($command)['destructive']);
        if (!$deletes && (preg_match(self::CREATE_NAME, $command) === 1 || !in_array($category, self::CONTENT_CATEGORIES, true))) {
            return null;
        }
        [$what, $target] = $this->effectTarget($command, $category, $params);
        return ['effect' => $deletes ? 'delete' : 'change', 'what' => $what, 'target' => $target, 'command' => $command];
    }

    /** A warned step's group in the warning, and the name of what it touches ('' when it names nothing). */
    private function effectTarget(string $command, string $category, array $params): array {
        $text = static function ($v): string {
            return is_scalar($v) ? (string) $v : '';
        };
        if ($category === 'content.write') {
            if ($command === 'renameComponent') return ['components', $text($params['oldName'] ?? '')];
            if ($command === 'deleteSnippet') return ['snippets', $text($params['id'] ?? '')];
            if ($command === 'editTitle') return ['pages', $text($params['route'] ?? '')];
            $type = $text($params['type'] ?? '');
            if ($type === 'menu' || $type === 'footer') return [$type, ''];
            if ($type === 'component') return ['components', $text($params['name'] ?? '')];
            return ['pages', $text($params['name'] ?? '')];
        }
        if ($category === 'translation.write') {
            if ($command === 'deleteLang') return ['languages', $text($params['code'] ?? '')];
            if ($command === 'setDefaultLang') return ['defaultLanguage', $text($params['lang'] ?? '')];
            if ($command === 'cleanOrphanTranslations') return ['translationFiles', ''];
            return ['texts', $text($params['language'] ?? '')];
        }
        if (isset(self::EFFECT_GROUPS[$category])) {
            [$what, $names] = self::EFFECT_GROUPS[$category];
            foreach ($names as $name) {
                if ($text($params[$name] ?? '') !== '') return [$what, $text($params[$name])];
            }
            return [$what, ''];
        }
        return ['other', $command];
    }

    /**
     * Expand a forEach step into multiple commands
     * 
     * @param array $step Step definition with forEach
     * @param array $context Full context (param, data, config)
     * @return array Expanded steps
     */
    private function expandForEach(array $step, array $context): array {
        $expanded = [];
        $items = $this->forEachItems($step['forEach'], $step['filter'] ?? null, $context);

        // Expand each item
        foreach ($items as $key => $value) {
            // Create item context for parameter resolution
            $itemContext = array_merge($context, [
                '$key' => $key,
                '$value' => $value,
                '$item' => $value  // Alias
            ]);
            
            $resolvedParams = $this->resolveStepParams($step['params'] ?? [], $itemContext);
            $resolvedParams = $this->resolveEachLoops($resolvedParams, $itemContext);
            $resolvedStep = [
                'command' => $step['command'],
                'method' => $step['method'] ?? 'POST',
                'params' => $resolvedParams
            ];
            
            // Carry over execution control properties
            foreach (['abortOnFail', 'retryOn', 'maxRetries', 'retryDelayMs'] as $prop) {
                if (isset($step[$prop])) {
                    $resolvedStep[$prop] = $step[$prop];
                }
            }
            
            $expanded[] = $resolvedStep;
        }
        
        return $expanded;
    }

    /**
     * The items a forEach goes through — a step's, or a sub-workflow entry's: its path read from the
     * context (a comma-separated string, as a form's "en,fr,es", split), then its filter applied.
     */
    private function forEachItems(string $path, ?string $filter, array $context): array {
        $items = $this->resolveDataPath($path, $context);
        if (is_string($items) && !empty($items)) {
            $items = array_map('trim', explode(',', $items));
        }
        if (!is_array($items)) {
            return [];
        }
        return $filter !== null ? $this->evaluateStepFilter($filter, $items, $context) : $items;
    }

    /**
     * Resolve a data path like "routes", "langData.languages", "param.keepAssets"
     * 
     * Supports:
     * - Direct data requirement IDs: "routes" → $context['data']['routes']
     * - Nested paths: "langData.languages" → $context['data']['langData']['languages']
     * - Param paths: "param.keepAssets" → $context['param']['keepAssets']
     * - Config paths: "config.x" → $context['config']['x']
     * - Every item of a list: "switchNow.children.*.params.data-lang" → each child's data-lang, as a list
     *
     * @param string $path Dot-notation path
     * @param array $context Full context
     * @return mixed Resolved value
     */
    private function resolveDataPath(string $path, array $context) {
        $parts = explode('.', $path);
        // A known context key starts at the context; anything else is a data requirement's id.
        $start = in_array($parts[0], ['param', 'data', 'config', '$key', '$value', '$item']) ? $context : ($context['data'] ?? []);
        return $this->walkPath($start, $parts);
    }

    /**
     * A path's value from $current, null when a part is missing. A '*' part reads the rest of the path
     * on every item of the array it stands at: their values, as a list (an item without one is left out).
     */
    private function walkPath($current, array $parts) {
        foreach ($parts as $i => $part) {
            if ($part === '*') {
                if (!is_array($current)) return null;
                $rest = array_slice($parts, $i + 1);
                $values = [];
                foreach ($current as $item) {
                    $value = $this->walkPath($item, $rest);
                    if ($value !== null) $values[] = $value;
                }
                return $values;
            }
            if (is_array($current) && isset($current[$part])) {
                $current = $current[$part];
            } else {
                return null;
            }
        }
        return $current;
    }
    
    /**
     * Evaluate a step condition
     * 
     * Supports:
     * - Simple path checks: "param.keepAssets"
     * - Comparisons: "param.keepAssets !== true"
     * - Two values compared: "param.defaultLanguage !== {{langData.default_language}}"
     * - AND conditions: "param.keepLanguages !== true && langData.multilingual === true"
     * 
     * @param string $condition Condition string
     * @param array $context Full context (param, data, config)
     * @return bool Whether condition is met
     */
    private function evaluateStepCondition(string $condition, array $context): bool {
        // Handle AND (&&) conditions
        if (strpos($condition, '&&') !== false) {
            $parts = array_map('trim', explode('&&', $condition));
            foreach ($parts as $part) {
                if (!$this->evaluateSingleCondition($part, $context)) {
                    return false;
                }
            }
            return true;
        }
        
        // Handle OR (||) conditions
        if (strpos($condition, '||') !== false) {
            $parts = array_map('trim', explode('||', $condition));
            foreach ($parts as $part) {
                if ($this->evaluateSingleCondition($part, $context)) {
                    return true;
                }
            }
            return false;
        }
        
        return $this->evaluateSingleCondition($condition, $context);
    }
    
    /**
     * Evaluate a single condition (no && or ||). The left side is a path; the right side of a
     * comparison is a literal or a {{path}} (comparisonValue()). A value read from the context is
     * normalised on either side (booleanLike()): a form sends "true" / "false" as strings.
     */
    private function evaluateSingleCondition(string $condition, array $context): bool {
        // Handle negation at start
        $negate = false;
        if (str_starts_with($condition, '!') && !str_starts_with($condition, '!=')) {
            $negate = true;
            $condition = ltrim(substr($condition, 1));
        }

        // Check for comparison operators (a path may read every item of a list, '*', and a key with a hyphen)
        if (preg_match('/^([\w.*-]+)\s*(===?|!==?|>=?|<=?)\s*(.+)$/', $condition, $matches)) {
            $leftPath = $matches[1];
            $operator = $matches[2];
            $rightValue = trim($matches[3]);

            $leftValue = $this->booleanLike($this->resolveDataPath($leftPath, $context));
            $rightParsed = $this->comparisonValue($rightValue, $context, true);

            $result = match($operator) {
                '=', '==' => $leftValue == $rightParsed,
                '===' => $leftValue === $rightParsed,
                '!=', '!==' => $leftValue !== $rightParsed,
                '>' => $leftValue > $rightParsed,
                '>=' => $leftValue >= $rightParsed,
                '<' => $leftValue < $rightParsed,
                '<=' => $leftValue <= $rightParsed,
                default => false
            };
            
            return $negate ? !$result : $result;
        }
        
        // Simple truthy check on path
        $value = $this->booleanLike($this->resolveDataPath($condition, $context));

        $result = !empty($value);

        return $negate ? !$result : $result;
    }

    /**
     * The right side of a comparison — in a step's condition, a sub-workflow's condition and a forEach
     * filter: a {{path}} is read from the context (normalised when $normalize, as a condition's left
     * side is), anything else is a literal (parseFilterValue()).
     */
    private function comparisonValue(string $raw, array $context, bool $normalize = false) {
        $raw = trim($raw);
        if (preg_match('/^\{\{(.+)\}\}$/D', $raw, $m)) {
            $value = $this->resolveDataPath(trim($m[1]), $context);
            return $normalize ? $this->booleanLike($value) : $value;
        }
        return $this->parseFilterValue($raw);
    }

    /** A value read from the context, its boolean-like strings as booleans: "true" / "1", "false" / "0". */
    private function booleanLike($value) {
        if ($value === 'true' || $value === '1') return true;
        if ($value === 'false' || $value === '0') return false;
        return $value;
    }
    
    /**
     * Evaluate filter expression for forEach items
     * 
     * @param string $filter Filter expression
     * @param array $items Items to filter
     * @param array $context Base context
     * @return array Filtered items
     */
    private function evaluateStepFilter(string $filter, array $items, array $context): array {
        $filtered = [];
        
        // Normalize filter - remove {{ }} if present for simpler parsing
        $normalizedFilter = preg_replace('/\{\{(\$\w+(?:\.\w+)?)\}\}/', '$1', $filter);
        
        foreach ($items as $key => $value) {
            $itemContext = array_merge($context, [
                '$key' => $key,
                '$value' => $value,
                '$item' => $value
            ]);
            
            // Support && (AND) operator — split into sub-expressions, all must pass
            $subExpressions = array_map('trim', explode('&&', $normalizedFilter));
            $allPass = true;
            
            foreach ($subExpressions as $expr) {
                if (!$this->evaluateSingleFilterExpr($expr, $key, $value, $itemContext)) {
                    $allPass = false;
                    break;
                }
            }
            
            if ($allPass) {
                $filtered[$key] = $value;
            }
        }
        
        return $filtered;
    }
    
    /**
     * Evaluate a single filter comparison expression
     */
    private function evaluateSingleFilterExpr(string $expr, $key, $value, array $itemContext): bool {
        if (preg_match('/^(\$\w+(?:\.\w+)?)\s*(===?|!==?|!=)\s*(.+)$/', $expr, $matches)) {
            $leftPath = $matches[1];
            $operator = $matches[2];
            $rightValue = trim($matches[3]);

            // Resolve left side (handle $key, $value, $value.field)
            if ($leftPath === '$key') {
                $leftResolved = $key;
            } elseif ($leftPath === '$value' || $leftPath === '$item') {
                $leftResolved = $value;
            } elseif (str_starts_with($leftPath, '$value.') || str_starts_with($leftPath, '$item.')) {
                $fieldPath = substr($leftPath, strpos($leftPath, '.') + 1);
                $leftResolved = is_array($value) ? ($value[$fieldPath] ?? null) : null;
            } else {
                $leftResolved = $this->resolveDataPath(ltrim($leftPath, '$'), $itemContext);
            }

            // The right side: a literal or a {{path}}, read as a condition reads it.
            $rightParsed = $this->comparisonValue($rightValue, $itemContext);

            return match($operator) {
                '=', '==' => $leftResolved == $rightParsed,
                '===' => $leftResolved === $rightParsed,
                '!=', '!==' => $leftResolved !== $rightParsed,
                default => false
            };
        }

        // Membership operators: `in` and `not_in`. Useful for diff-style
        // forEach steps such as "delete every existing language that the
        // user no longer has selected":
        //
        //   "forEach": "data.langData.languages",
        //   "filter": "{{$value}} not_in {{param.languages}}",
        //   "command": "deleteLang", "params": { "code": "{{$value}}" }
        if (preg_match('/^(.+?)\s+(in|not_in)\s+(.+)$/', $expr, $matches)) {
            $leftRaw = trim($matches[1]);
            $operator = $matches[2];
            $rightRaw = trim($matches[3]);

            // Resolve left value
            if ($leftRaw === '$key') {
                $leftValue = $key;
            } elseif ($leftRaw === '$value' || $leftRaw === '$item') {
                $leftValue = $value;
            } elseif (str_starts_with($leftRaw, '$value.') || str_starts_with($leftRaw, '$item.')) {
                $fieldPath = substr($leftRaw, strpos($leftRaw, '.') + 1);
                $leftValue = is_array($value) ? ($value[$fieldPath] ?? null) : null;
            } elseif (preg_match('/^\{\{(.+)\}\}$/', $leftRaw, $leftTpl)) {
                $leftValue = $this->resolveDataPath(trim($leftTpl[1]), $itemContext);
            } else {
                // Bare path or literal — try data path first, fall back to literal.
                $leftValue = $this->resolveDataPath($leftRaw, $itemContext);
                if ($leftValue === null) {
                    $leftValue = $this->parseFilterValue($leftRaw);
                }
            }

            // Resolve right side as an array
            if (preg_match('/^\{\{(.+)\}\}$/', $rightRaw, $rightTpl)) {
                $rightArr = $this->resolveDataPath(trim($rightTpl[1]), $itemContext);
            } else {
                $rightArr = $this->resolveDataPath($rightRaw, $itemContext);
            }

            // If the right side isn't an array (e.g., the data path didn't
            // resolve, or the workflow author pointed at a scalar), `in`
            // returns false and `not_in` returns true — matching the
            // intuitive read of "is X in this list?" / "is X NOT in this list?"
            // when the list is empty.
            if (!is_array($rightArr)) {
                return $operator === 'not_in';
            }

            $isMember = in_array($leftValue, $rightArr, true);
            return $operator === 'in' ? $isMember : !$isMember;
        }

        // No operator - truthy check
        return true;
    }
    
    /**
     * Parse a filter/condition value (handle quotes, numbers, booleans)
     * 
     * @param string $value Raw value string
     * @return mixed Parsed value
     */
    private function parseFilterValue(string $value) {
        // Quoted string
        if (preg_match('/^["\'](.*)["\']\s*$/', $value, $matches)) {
            return $matches[1];
        }
        
        // Boolean
        if ($value === 'true') return true;
        if ($value === 'false') return false;
        if ($value === 'null') return null;
        
        // Number
        if (is_numeric($value)) {
            return strpos($value, '.') !== false ? (float)$value : (int)$value;
        }
        
        return $value;
    }
    
    /**
     * Resolve step parameters with context values
     * 
     * @param array $params Parameter definitions
     * @param array $context Full context including $key, $value for forEach
     * @return array Resolved parameters
     */
    private function resolveStepParams(array $params, array $context): array {
        $resolved = [];
        
        foreach ($params as $key => $value) {
            $resolved[$key] = $this->resolveStepParamValue($value, $context);
        }
        
        return $resolved;
    }
    
    /**
     * Resolve a single parameter value
     * 
     * @param mixed $value Value to resolve
     * @param array $context Context for resolution
     * @return mixed Resolved value
     */
    private function resolveStepParamValue($value, array $context) {
        if (is_string($value)) {
            // First, resolve simple $value/$key placeholders that may be nested
            // This handles {{__current_page;lang={{$value}}}} -> {{__current_page;lang=en}}
            $value = preg_replace_callback('/\{\{\$(\w+)\}\}/', function($matches) use ($context) {
                $varName = '$' . $matches[1];
                if (isset($context[$varName])) {
                    $val = $context[$varName];
                    return is_scalar($val) ? $val : json_encode($val);
                }
                return $matches[0];
            }, $value);
            
            // Handle {{placeholder}} syntax (full value is a placeholder)
            if (preg_match('/^\{\{([^{}]+)\}\}$/', $value, $matches)) {
                $path = trim($matches[1]);
                // Preserve system placeholders (e.g. {{__current_page;lang=en}}) — they're resolved at render time
                if (str_starts_with($path, '__')) {
                    return $value;
                }
                // Use empty string as fallback for missing params (not the raw template)
                // This ensures unresolved {{param.X}} becomes '' rather than literal '{{param.X}}'
                $resolved = $this->resolvePathWithFilters($path, $context, '');
                return $resolved;
            }
            
            // Handle inline {{placeholders}} in strings (not containing nested braces)
            return preg_replace_callback('/\{\{([^{}]+)\}\}/', function($matches) use ($context) {
                $path = trim($matches[1]);
                // Preserve system placeholders — resolved at render time, not workflow time
                if (str_starts_with($path, '__')) {
                    return $matches[0];
                }
                $resolved = $this->resolvePathWithFilters($path, $context, '');
                return is_scalar($resolved) ? $resolved : json_encode($resolved);
            }, $value);
        }
        
        if (is_array($value)) {
            // Don't resolve $each templates prematurely — the $item template
            // contains {{$value}} references that need loop context from resolveEachLoops.
            // Only resolve the $each source here; leave $item intact.
            if (isset($value['$each'])) {
                $value['$each'] = $this->resolveStepParamValue($value['$each'], $context);
                return $value;
            }
            return $this->resolveStepParams($value, $context);
        }
        
        return $value;
    }
    
    /**
     * Resolve a path with optional filters (e.g., {{$value | uppercase}})
     * 
     * @param string $path The path possibly containing filters
     * @param array $context Context for resolution
     * @param mixed $fallback Fallback value if unresolved
     * @return mixed Resolved and filtered value
     */
    private function resolvePathWithFilters(string $path, array $context, $fallback = null) {
        // Check for filter syntax: "path | filter"
        $filter = null;
        if (str_contains($path, '|')) {
            $parts = explode('|', $path, 2);
            $path = trim($parts[0]);
            $filter = trim($parts[1]);
        }
        
        // Resolve the base value
        $resolved = null;
        
        // Handle special forEach variables
        if ($path === '$key') {
            $resolved = $context['$key'] ?? $fallback;
        } elseif ($path === '$value' || $path === '$item') {
            $resolved = $context['$value'] ?? $fallback;
        } elseif (str_starts_with($path, '$value.') || str_starts_with($path, '$item.')) {
            $fieldPath = substr($path, strpos($path, '.') + 1);
            $itemValue = $context['$value'] ?? [];
            $resolved = is_array($itemValue) ? ($itemValue[$fieldPath] ?? $fallback) : $fallback;
        } else {
            // Normal path resolution
            $resolved = $this->resolveDataPath($path, $context) ?? $fallback;
        }
        
        // Apply filter if present
        if ($filter !== null && is_string($resolved)) {
            $resolved = $this->applyFilter($resolved, $filter);
        }
        
        return $resolved;
    }
    
    /**
     * Apply a filter to a value
     * 
     * @param string $value The value to filter
     * @param string $filter The filter name
     * @return string Filtered value
     */
    private function applyFilter(string $value, string $filter): string {
        return match($filter) {
            'uppercase', 'upper' => strtoupper($value),
            'lowercase', 'lower' => strtolower($value),
            'ucfirst', 'capitalize' => ucfirst($value),
            'ucwords', 'title' => ucwords($value),
            'trim' => trim($value),
            // A phone number as a `tel:` address takes it: its digits and a leading +, the spaces and dots a person types left
            // out, and the "(0)" some write after a country code, which is not dialled with it.
            'tel' => str_starts_with(ltrim($value), '+')
                ? '+' . preg_replace('/\D+/', '', str_replace('(0)', '', $value))
                : preg_replace('/\D+/', '', $value),
            'langname', 'language' => qs_language_label($value),
            default => $value
        };
    }
    
    /**
     * Evaluate a condition for data requirements
     * 
     * @param string $condition Condition string (param name or expression)
     * @param array $userParams User parameters
     * @return bool Whether condition is met
     */
    private function evaluateDataCondition(string $condition, array $userParams): bool {
        // Handle AND (&&) conditions
        if (strpos($condition, '&&') !== false) {
            $parts = array_map('trim', explode('&&', $condition));
            foreach ($parts as $part) {
                if (!$this->evaluateDataCondition($part, $userParams)) {
                    return false;
                }
            }
            return true;
        }
        
        // Handle OR (||) conditions
        if (strpos($condition, '||') !== false) {
            $parts = array_map('trim', explode('||', $condition));
            foreach ($parts as $part) {
                if ($this->evaluateDataCondition($part, $userParams)) {
                    return true;
                }
            }
            return false;
        }
        
        // Handle negation
        $negate = false;
        if (str_starts_with($condition, '!') && !str_starts_with($condition, '!=')) {
            $negate = true;
            $condition = ltrim(substr($condition, 1));
        }
        
        // Handle comparison operators (e.g., "multilingual === true", "pages > 1")
        if (preg_match('/^([\w.]+)\s*(===?|!==?|>=?|<=?)\s*(.+)$/', $condition, $matches)) {
            $paramName = trim($matches[1]);
            $operator = $matches[2];
            $rightRaw = trim($matches[3]);
            
            // Resolve left side
            $left = $userParams[$paramName] ?? null;
            // Normalize boolean-like strings
            if ($left === 'true' || $left === 'on' || $left === '1') $left = true;
            if ($left === 'false' || $left === 'off' || $left === '0') $left = false;
            
            // Parse right side
            $right = match($rightRaw) {
                'true' => true,
                'false' => false,
                'null' => null,
                default => is_numeric($rightRaw) ? (int)$rightRaw : trim($rightRaw, "'\"")
            };
            
            $result = match($operator) {
                '=', '==' => $left == $right,
                '===' => $left === $right,
                '!=' => $left != $right,
                '!==' => $left !== $right,
                '>' => $left > $right,
                '>=' => $left >= $right,
                '<' => $left < $right,
                '<=' => $left <= $right,
                default => false
            };
            
            return $negate ? !$result : $result;
        }
        
        // Simple param check - just check if param is truthy
        if (isset($userParams[$condition])) {
            $value = $userParams[$condition];
            $result = !empty($value) && $value !== 'false' && $value !== '0';
            return $negate ? !$result : $result;
        }
        
        // Param doesn't exist - condition not met
        return $negate ? true : false;
    }
    
    /**
     * Render a prompt template with all variables substituted
     * 
     * @param array $workflow The workflow definition
     * @param array $userParams User-provided parameters
     * @param array $fetchedData Data fetched from dataRequirements
     * @return string Rendered prompt
     */
    public function renderPrompt(array $workflow, array $userParams, array $fetchedData): string {
        // The commands this run's prompt teaches: those whose condition holds for its parameters (relatedCommands()).
        $workflow = $this->withCommandsFor($workflow, $userParams);

        // Get the prompt template from workflow
        $template = $workflow['promptTemplate'] ?? '';
        
        // If template is a filename (ends with .md), load it directly
        if (preg_match('/\.md$/D', $template)) {
            $folder = $workflow['_folder'] ?? ($this->workflowsBasePath . '/core');
            $mdPath = $folder . '/' . $template;
            
            if (file_exists($mdPath)) {
                $template = file_get_contents($mdPath);
            } else {
                return "Error: Template file not found: $template";
            }
        }
        
        // Build commands context from relatedCommands + fetched help data
        $commandsContext = $this->buildCommandsContext($workflow, $fetchedData);
        
        // Reset per-render help cache and seed it with already-fetched help data
        $this->helpCache = $commandsContext;
        
        // Auto-inject pins / warnings as partial references at the top of the template,
        // unless meta.suppressPinsHeader is true. The resolver below inlines them.
        $template = $this->prependPinsWarnings($template, $workflow);
        
        // Resolve {{> name}}, {{> command.X}}, {{> command.$relatedCommands}},
        // {{> pin.X}}, {{> warning.X}}, {{> example.X}} BEFORE conditionals/loops, so the
        // inlined block content participates in the regular template passes.
        $template = $this->resolvePartials($template, $workflow);
        
        // First pass: Handle {{#if}} conditionals
        $template = $this->processConditionals($template, $userParams, $fetchedData);
        
        // Second pass: Replace {{#each}} loops (supports data.X and commands)
        $template = $this->processEachLoops($template, $fetchedData, $commandsContext);
        
        // Third pass: Replace {{json X}} helper — JSON-encode a data value
        $template = preg_replace_callback('/\{\{json\s+(\w+)\}\}/', function($matches) use ($fetchedData) {
            $key = $matches[1];
            if (!isset($fetchedData[$key])) return '(no data)';
            $value = $fetchedData[$key];
            if (is_array($value) && isset($value['_error'])) return "(error loading {$key})";
            return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }, $template);
        
        // Fourth pass: Replace direct data references {{data.xxx}} and {{data.xxx.yyy}}
        $template = preg_replace_callback('/\{\{data\.(\w+)(?:\.(\w+))?\}\}/', function($matches) use ($fetchedData) {
            $key = $matches[1];
            $subKey = $matches[2] ?? null;
            
            if (!isset($fetchedData[$key])) {
                return '(no data)';
            }
            
            $value = $fetchedData[$key];
            
            // Check if there was an error fetching
            if (is_array($value) && isset($value['_error'])) {
                return "(error loading {$key})";
            }
            
            if ($subKey && is_array($value)) {
                $value = $value[$subKey] ?? null;
            }
            
            return $this->formatValue($value);
        }, $template);
        
        // Fifth pass: Replace {{param.xxx}} user parameters. A list (a tag-select's values, sent as JSON)
        // is written as the data passes write one, its items joined by ", "; any other value as it came.
        $template = preg_replace_callback('/\{\{param\.(\w+)\}\}/', function($matches) use ($userParams) {
            $value = $userParams[$matches[1]] ?? '';
            return is_array($value) ? $this->formatValue($value) : (string) $value;
        }, $template);
        
        // Sixth pass: Replace {{helpers.xxx}} - static helper values
        $template = preg_replace_callback('/\{\{helpers\.(\w+)(?:\s+([^}]+))?\}\}/', function($matches) {
            $helper = $matches[1];
            $args = isset($matches[2]) ? trim($matches[2]) : '';
            
            return match($helper) {
                'date' => date('Y-m-d'),
                'datetime' => date('Y-m-d H:i:s'),
                'timestamp' => time(),
                default => ''
            };
        }, $template);
        
        // Seventh pass: Replace bare data references {{xxx}} and {{xxx.yyy}}
        // This catches any remaining {{word}} or {{word.word}} that wasn't handled above
        $template = preg_replace_callback('/\{\{(\w+)(?:\.(\w+))?\}\}/', function($matches) use ($fetchedData) {
            $key = $matches[1];
            $subKey = $matches[2] ?? null;
            
            if (!isset($fetchedData[$key])) return $matches[0]; // leave unknown placeholders
            $value = $fetchedData[$key];
            if (is_array($value) && isset($value['_error'])) return "(error loading {$key})";
            
            if ($subKey && is_array($value)) {
                $value = $value[$subKey] ?? null;
            }
            
            return $this->formatValue($value);
        }, $template);
        
        return trim($template);
    }
    
    /**
     * Process {{#if}} conditionals in template: {{#if condition}}...{{/if}}, with an optional
     * {{else}}. A block may hold another: the innermost blocks are decided first, then the ones
     * around them, until none is left.
     */
    private function processConditionals(string $template, array $userParams, array $fetchedData): string {
        // A block with no {{#if inside it.
        $pattern = '/\{\{#if\s+((?:(?!\}\}).)+?)\}\}((?:(?!\{\{#if\s).)*?)(?:\{\{else\}\}((?:(?!\{\{#if\s).)*?))?\{\{\/if\}\}/s';

        do {
            $before = $template;
            $template = (string) preg_replace_callback($pattern, function($matches) use ($userParams, $fetchedData) {
                $condition = trim($matches[1]);
                $ifContent = $matches[2];
                $elseContent = $matches[3] ?? '';

                $conditionMet = $this->evaluateTemplateCondition($condition, $userParams, $fetchedData);

                return $conditionMet ? $ifContent : $elseContent;
            }, $template);
        } while ($template !== $before);

        return $template;
    }
    
    /**
     * Evaluate a template condition
     */
    private function evaluateTemplateCondition(string $condition, array $userParams, array $fetchedData): bool {
        // Handle negation
        $negate = false;
        if (str_starts_with($condition, '!')) {
            $negate = true;
            $condition = substr($condition, 1);
        }
        
        // Check for comparison operators
        if (preg_match('/^([\w.]+)\s*(===?|!==?|>=?|<=?)\s*(.+)$/', $condition, $matches)) {
            $left = $this->resolveTemplateValue($matches[1], $userParams, $fetchedData);
            $operator = $matches[2];
            $right = $this->parseFilterValue(trim($matches[3]));
            
            $result = match($operator) {
                '=', '==' => $left == $right,
                '===' => $left === $right,
                '!=' => $left != $right,
                '!==' => $left !== $right,
                '>' => $left > $right,
                '>=' => $left >= $right,
                '<' => $left < $right,
                '<=' => $left <= $right,
                default => false
            };
            
            return $negate ? !$result : $result;
        }
        
        // Simple existence/truthy check
        $value = $this->resolveTemplateValue($condition, $userParams, $fetchedData);
        $result = !empty($value) && $value !== 'false' && $value !== '0';
        
        return $negate ? !$result : $result;
    }
    
    /**
     * Resolve a value reference in template conditions
     */
    private function resolveTemplateValue(string $ref, array $userParams, array $fetchedData) {
        // Check for param.xxx
        if (str_starts_with($ref, 'param.')) {
            $key = substr($ref, 6);
            $value = $userParams[$key] ?? null;
            // Normalize boolean-like strings from URL query parameters
            if ($value === 'true' || $value === 'on' || $value === '1') return true;
            if ($value === 'false' || $value === 'off' || $value === '0') return false;
            return $value;
        }
        
        // Check for data.xxx
        if (str_starts_with($ref, 'data.')) {
            $parts = explode('.', substr($ref, 5));
            $value = $fetchedData;
            foreach ($parts as $part) {
                if (is_array($value) && isset($value[$part])) {
                    $value = $value[$part];
                } else {
                    return null;
                }
            }
            return $value;
        }
        
        // Check fetchedData for bare references (e.g., "styles.css" → $fetchedData['styles']['css'])
        $parts = explode('.', $ref);
        if (count($parts) >= 1 && isset($fetchedData[$parts[0]])) {
            $value = $fetchedData[$parts[0]];
            for ($i = 1; $i < count($parts); $i++) {
                if (is_array($value) && isset($value[$parts[$i]])) {
                    $value = $value[$parts[$i]];
                } else {
                    return null;
                }
            }
            return $value;
        }
        
        // Direct param check (for backward compatibility)
        return $userParams[$ref] ?? null;
    }
    
    /**
     * Build commands context from relatedCommands + fetched help data
     * 
     * Maps relatedCommands to their help data fetched via dataRequirements.
     * Looks for dataRequirement IDs matching "help*" that used the help command.
     */
    private function buildCommandsContext(array $workflow, array $fetchedData): array {
        $relatedCommands = $workflow['relatedCommands'] ?? [];
        if (empty($relatedCommands)) return [];
        
        $commands = [];
        foreach ($relatedCommands as $cmdName) {
            // Try to find help data in fetchedData (convention: "helpCommandName" or "help_commandName")
            $found = false;
            foreach ($fetchedData as $dataId => $dataValue) {
                // Match helpEditStyles, helpSetRootVariables, etc.
                $normalizedCmd = strtolower($cmdName);
                $normalizedId = strtolower($dataId);
                if ($normalizedId === 'help' . $normalizedCmd || $normalizedId === 'help_' . $normalizedCmd) {
                    if (is_array($dataValue) && !isset($dataValue['_error'])) {
                        $commands[$cmdName] = $dataValue;
                        $found = true;
                        break;
                    }
                }
            }
            if (!$found) {
                // Try fetching help live if CommandRunner is available
                try {
                    $helpData = CommandRunner::extractData('help', [], [$cmdName], 'data');
                    if ($helpData) {
                        $commands[$cmdName] = $helpData;
                    }
                } catch (\Throwable $e) {
                    // Skip this command
                }
            }
        }
        
        return $commands;
    }
    
    /**
     * Process {{#each}} loops in template
     * 
     * Supports:
     * - {{#each data.xxx}} — iterate over fetchedData[xxx]
     * - {{#each commands}} — iterate over built commands context
     * - {{@key}}, {{this}}, {{this.field}} — standard loop variables
     */
    private function processEachLoops(string $template, array $fetchedData, array $commandsContext = []): string {
        // Generic pattern: {{#each SOMETHING}}...{{/each}}
        $pattern = '/\{\{#each\s+([\w.]+)\}\}(.*?)\{\{\/each\}\}/s';
        
        return preg_replace_callback($pattern, function($matches) use ($fetchedData, $commandsContext) {
            $source = $matches[1];
            $loopContent = $matches[2];
            
            // Resolve the items to iterate over
            $items = null;
            if ($source === 'commands') {
                $items = $commandsContext;
            } elseif (str_starts_with($source, 'data.')) {
                $dataKey = substr($source, 5);
                $items = $fetchedData[$dataKey] ?? null;
            } else {
                // Bare name — check fetchedData
                $items = $fetchedData[$source] ?? null;
            }
            
            if (!is_array($items) || empty($items)) {
                return '';
            }
            
            $output = '';
            
            foreach ($items as $key => $item) {
                $itemOutput = $loopContent;

                // Replace {{@key}}
                $itemOutput = str_replace('{{@key}}', (string)$key, $itemOutput);
                
                // Replace {{this}} with the whole item (formatted)
                $itemOutput = str_replace('{{this}}', $this->formatValue($item), $itemOutput);
                
                // Replace {{this.field}} for object properties
                if (is_array($item)) {
                    $itemOutput = preg_replace_callback('/\{\{this\.(\w+)\}\}/', function($m) use ($item) {
                        return isset($item[$m[1]]) ? $this->formatValue($item[$m[1]]) : '';
                    }, $itemOutput);
                }
                
                $output .= $itemOutput;
            }
            
            return $output;
        }, $template);
    }
    
    /**
     * Format a value for output in prompt
     */
    private function formatValue($value): string {
        if (is_null($value)) {
            return '(empty)';
        }
        
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        
        if (is_array($value)) {
            // Check if it's an associative array (object-like)
            if (array_keys($value) !== range(0, count($value) - 1)) {
                return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            }
            
            // Simple array - format as list
            return implode(', ', array_map(function($v) {
                return is_scalar($v) ? (string)$v : json_encode($v, JSON_UNESCAPED_UNICODE);
            }, $value));
        }
        
        return (string)$value;
    }
    
    /**
     * Validate a workflow against the schema
     * 
     * @param array $workflow Workflow data to validate
     * @return array Validation result with 'valid' boolean and 'errors' array
     */
    public function validateWorkflow(array $workflow): array {
        $errors = [];
        
        // Required fields - only id, version, and meta are truly required
        $requiredFields = ['id', 'version', 'meta'];
        foreach ($requiredFields as $field) {
            if (!isset($workflow[$field])) {
                $errors[] = "Missing required field: {$field}";
            }
        }
        
        // Must have a promptTemplate or steps. With both it is an AI workflow (isAiWorkflow()): its steps
        // are not run, which is said as a warning so a workflow that loads today keeps loading.
        if (!isset($workflow['promptTemplate']) && !isset($workflow['steps'])) {
            $errors[] = "Workflow must have either 'promptTemplate' or 'steps'";
        }
        
        // Validate meta
        if (isset($workflow['meta'])) {
            $meta = $workflow['meta'];
            
            // Must have either titleKey (core/i18n) or name (custom/direct)
            if (!isset($meta['titleKey']) && !isset($meta['name'])) {
                $errors[] = "Missing required meta field: titleKey or name";
            }
            if (!isset($meta['category'])) {
                $errors[] = "Missing required meta field: category";
            } elseif (!is_string($meta['category']) || trim($meta['category']) === '') {
                // Any group name runs: the panel lists one the schema does not know (categories()) last, under its own name,
                // so an older file keeps running.
                $errors[] = "Invalid category: it must name a group. The panel's groups are: " . implode(', ', $this->categories());
            }
        }
        
        // Validate parameters if present
        if (isset($workflow['parameters'])) {
            foreach ($workflow['parameters'] as $index => $param) {
                if (!isset($param['id'])) {
                    $errors[] = "Parameter at index {$index} missing required field: id";
                }
                if (!isset($param['type'])) {
                    $errors[] = "Parameter at index {$index} missing required field: type";
                }
            }
        }
        
        // Validate dataRequirements if present
        if (isset($workflow['dataRequirements'])) {
            foreach ($workflow['dataRequirements'] as $index => $req) {
                if (!isset($req['id'])) {
                    $errors[] = "Data requirement at index {$index} missing required field: id";
                }
                if (!isset($req['command'])) {
                    $errors[] = "Data requirement at index {$index} missing required field: command";
                }
            }
        }
        
        // A related command is a name, or a name with a condition.
        if (isset($workflow['relatedCommands'])) {
            foreach ((array) $workflow['relatedCommands'] as $index => $entry) {
                if (is_string($entry) && $entry !== '') continue;
                if (is_array($entry) && is_string($entry['command'] ?? null) && $entry['command'] !== ''
                        && (!array_key_exists('condition', $entry) || is_string($entry['condition']))) continue;
                $errors[] = "relatedCommands[{$index}] must be a command's name, or an object with a 'command' and an optional 'condition'";
            }
        }

        // Validate steps if present
        if (isset($workflow['steps'])) {
            foreach ($workflow['steps'] as $index => $step) {
                if (!isset($step['command']) && !isset($step['template'])) {
                    $errors[] = "Step at index {$index} missing required field: command or template";
                }
            }
        }
        
        // A workflow that takes its steps from a template names the parameter that picks it, and that parameter is a
        // picker of templates. It asks no model: its steps are a file's.
        if (isset($workflow['stepsFrom'])) {
            $picker = null;
            foreach (($workflow['parameters'] ?? []) as $param) {
                if (is_array($param) && ($param['id'] ?? null) === $workflow['stepsFrom']) $picker = $param;
            }
            if (!is_string($workflow['stepsFrom']) || $picker === null || ($picker['type'] ?? null) !== 'template') {
                $errors[] = "'stepsFrom' must name a parameter of this workflow whose type is template";
            }
            if (isset($workflow['promptTemplate'])) {
                $errors[] = "A workflow with 'stepsFrom' takes its steps from a template: it has no promptTemplate";
            }
        }

        // Validate pins / warnings — must be string arrays whose IDs map to existing files.
        $warnings = [];
        if (isset($workflow['promptTemplate'], $workflow['steps'])) {
            $warnings[] = "Workflow has both 'promptTemplate' and 'steps': it runs as an AI workflow and its steps are not run (put them in a pre- or post-workflow)";
        }
        foreach (['pins' => 'pins', 'warnings' => 'warnings'] as $field => $folder) {
            if (!isset($workflow[$field])) continue;
            if (!is_array($workflow[$field])) {
                $errors[] = "Field '{$field}' must be an array of string IDs";
                continue;
            }
            foreach ($workflow[$field] as $i => $id) {
                if (!is_string($id) || $id === '') {
                    $errors[] = "{$field}[{$i}] must be a non-empty string ID";
                    continue;
                }
                $path = $this->workflowsBasePath . '/' . $folder . '/' . $id . '.md';
                if (!file_exists($path)) {
                    $warnings[] = "{$field}[{$i}] references missing file: {$folder}/{$id}.md";
                }
            }
        }
        
        // Scan promptTemplate (when it's a .md filename) for {{> name}} references and warn on missing files.
        if (isset($workflow['promptTemplate']) && preg_match('/\.md$/D', (string)$workflow['promptTemplate'])) {
            $folder = $workflow['_folder'] ?? ($this->workflowsBasePath . '/core');
            $mdPath = $folder . '/' . $workflow['promptTemplate'];
            if (file_exists($mdPath)) {
                $tplContent = (string)@file_get_contents($mdPath);
                if (preg_match_all('/\{\{>\s+([A-Za-z][\w.\-\$]*)\}\}/', $tplContent, $m)) {
                    foreach (array_unique($m[1]) as $partialName) {
                        // Skip command.* — resolved at runtime, not file-backed.
                        if (str_starts_with($partialName, 'command.')) continue;
                        $path = $this->partialFilePath($partialName);
                        if ($path === null || !file_exists($path)) {
                            $warnings[] = "Template references missing partial: {{> {$partialName}}}";
                        }
                    }
                }
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }
    
    /**
     * Check if a workflow requires AI generation
     * 
     * @param array $workflow The workflow definition
     * @return bool True if workflow has a promptTemplate
     */
    public function isAiWorkflow(array $workflow): bool {
        return isset($workflow['promptTemplate']);
    }
    
    /**
     * Format a single command's help data as clean markdown for inclusion in an AI prompt.
     *
     * Used by the {{> command.X}} partial resolver. Default `$concise = true` keeps prompts tight, with
     * the first example; verbose mode adds error_responses and every example.
     *
     * @param string $command   Command name (used for the heading)
     * @param array  $helpData  Output of `help` command for this command (description, method,
     *                          parameters, example_*, notes, error_responses, ...)
     * @param bool   $concise   When true (default) drop error_responses and keep one example.
     * @return string Markdown block (no trailing newline)
     */
    public function formatCommandFull(string $command, array $helpData, bool $concise = true): string {
        $out = ["### **{$command}**"];
        
        if (!empty($helpData['description'])) {
            $out[] = '';
            $out[] = (string)$helpData['description'];
        }
        
        if (!empty($helpData['method'])) {
            $out[] = '';
            $out[] = "- **Method:** `" . (string)$helpData['method'] . "`";
            if (!empty($helpData['url'])) {
                $out[] = "- **URL:** `" . (string)$helpData['url'] . "`";
            }
        }
        
        // Parameters block — table when multiple, bullet list when one.
        if (!empty($helpData['parameters']) && is_array($helpData['parameters'])) {
            $out[] = '';
            $out[] = '**Parameters:**';
            foreach ($helpData['parameters'] as $pname => $pdef) {
                if (!is_array($pdef)) { $pdef = ['description' => (string)$pdef]; }
                $required = !empty($pdef['required']) ? ' *(required)*' : '';
                $type = isset($pdef['type']) ? " `" . (string)$pdef['type'] . "`" : '';
                $desc = (string)($pdef['description'] ?? '');
                $out[] = "- **{$pname}**{$type}{$required} — {$desc}";
            }
        }
        
        // Examples — the first in concise mode, each as an answer writes it where help shows a body.
        $examples = $this->helpExamples($command, $helpData);
        if ($concise) {
            $examples = array_slice($examples, 0, 1);
        }
        foreach ($examples as $index => $example) {
            if ($index === 0) {
                $out[] = '';
                $out[] = '**Example:**';
            }
            if (is_array($example)) {
                $out[] = '```json';
                $out[] = json_encode($example, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $out[] = '```';
            } else {
                $out[] = '```';
                $out[] = $example;
                $out[] = '```';
            }
        }
        
        if (!empty($helpData['notes'])) {
            $out[] = '';
            $out[] = '**Notes:** ' . (is_array($helpData['notes']) ? implode(' ', $helpData['notes']) : (string)$helpData['notes']);
        }
        
        if (!$concise && !empty($helpData['error_responses']) && is_array($helpData['error_responses'])) {
            $out[] = '';
            $out[] = '**Error responses:**';
            foreach ($helpData['error_responses'] as $code => $msg) {
                $out[] = "- `{$code}` — " . (is_array($msg) ? json_encode($msg) : (string)$msg);
            }
        }
        
        return implode("\n", $out);
    }
    
    /**
     * A command's examples from its help entry: every key beginning `example`, in the entry's order. Each
     * is {command, params} when the line shows a request's body, the shape an AI answer uses; a line that
     * shows none stays as it is.
     *
     * @return array Of arrays and strings
     */
    private function helpExamples(string $command, array $helpData): array {
        $examples = [];
        foreach ($helpData as $key => $value) {
            if (strncmp((string) $key, 'example', 7) !== 0) continue;
            if (is_array($value)) {
                $examples[] = $value;
                continue;
            }
            $line = (string) $value;
            $body = $this->firstJsonObject($line, (int) strpos($line, 'with body:'));
            $examples[] = strpos($line, 'with body:') !== false && $body !== null
                ? ['command' => $command, 'params' => $body]
                : $line;
        }
        return $examples;
    }

    /** The first whole JSON object of a text from an offset, decoded; null when there is none. */
    private function firstJsonObject(string $text, int $from): ?array {
        $start = strpos($text, '{', $from);
        if ($start === false) return null;
        $depth = 0;
        $inString = false;
        $length = strlen($text);
        for ($i = $start; $i < $length; $i++) {
            $char = $text[$i];
            if ($inString) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === '"') {
                    $inString = false;
                }
                continue;
            }
            if ($char === '"') {
                $inString = true;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}' && --$depth === 0) {
                $decoded = json_decode(substr($text, $start, $i - $start + 1), true);
                return is_array($decoded) ? $decoded : null;
            }
        }
        return null;
    }

    /**
     * Look up help data for a single command, going through the per-render cache first.
     * Returns null if neither cache nor live fetch yields data.
     */
    private function resolveHelpForCommand(string $command): ?array {
        if (isset($this->helpCache[$command]) && is_array($this->helpCache[$command])) {
            return $this->helpCache[$command];
        }
        try {
            $helpData = CommandRunner::extractData('help', [], [$command], 'data');
            if (is_array($helpData)) {
                $this->helpCache[$command] = $helpData;
                return $helpData;
            }
        } catch (\Throwable $e) {
            error_log("[WorkflowManager] resolveHelpForCommand('$command') failed: " . $e->getMessage());
        }
        return null;
    }
    
    /**
     * Prepend ## Pins / ## Warnings sections (as {{> pin.X}} / {{> warning.X}} references)
     * to the template, when the workflow declares them. The partial resolver inlines the
     * actual content. Workflows can opt out via meta.suppressPinsHeader = true.
     */
    private function prependPinsWarnings(string $template, array $workflow): string {
        if (!empty($workflow['meta']['suppressPinsHeader'])) {
            return $template;
        }
        $prefix = '';
        $pins = $workflow['pins'] ?? [];
        $warnings = $workflow['warnings'] ?? [];
        
        if (is_array($pins) && !empty($pins)) {
            $prefix .= "## Pins\n\n";
            foreach ($pins as $id) {
                if (is_string($id) && $id !== '') {
                    $prefix .= "{{> pin.{$id}}}\n\n";
                }
            }
        }
        if (is_array($warnings) && !empty($warnings)) {
            $prefix .= "## Warnings\n\n";
            foreach ($warnings as $id) {
                if (is_string($id) && $id !== '') {
                    $prefix .= "{{> warning.{$id}}}\n\n";
                }
            }
        }
        if ($prefix !== '') {
            $prefix .= "---\n\n";
        }
        return $prefix . $template;
    }
    
    /**
     * Resolve {{> name}} partial references.
     *
     * Supported forms:
     *   {{> blockname}}                  → secure/admin/workflows/blocks/blockname.md
     *   {{> pin.X}}                      → secure/admin/workflows/pins/X.md
     *   {{> warning.X}}                  → secure/admin/workflows/warnings/X.md
     *   {{> example.X}}                  → secure/admin/workflows/examples/X.md
     *   {{> command.X}}                  → formatCommandFull(X, helpCache[X])
     *   {{> command.$relatedCommands}}   → formatCommandFull for each command in the
     *                                       workflow's relatedCommands list, joined with ---
     *
     * Recursive: inlined block content is itself scanned for further {{> ...}} references
     * up to PARTIALS_MAX_DEPTH levels deep. Cycles are detected via the $visited set.
     * Unknown / missing partials are LEFT INTACT in the template (not silently dropped)
     * and an error_log entry is written so typos are visible.
     */
    private function resolvePartials(string $template, array $workflow = [], int $depth = 0, array $visited = []): string {
        if ($depth >= self::PARTIALS_MAX_DEPTH) {
            error_log('[WorkflowManager] resolvePartials: max depth reached, leaving remaining placeholders intact');
            return $template;
        }
        
        $pattern = '/\{\{>\s+([A-Za-z][\w.\-\$]*)\}\}/';
        
        return preg_replace_callback($pattern, function($matches) use ($workflow, $depth, $visited) {
            $name = $matches[1];
            
            if (isset($visited[$name])) {
                error_log("[WorkflowManager] resolvePartials: cycle detected on '{$name}'");
                return $matches[0];
            }
            $visited[$name] = true;
            
            $resolved = $this->resolvePartialName($name, $workflow);
            if ($resolved === null) {
                // Leave placeholder intact, log already written by resolvePartialName.
                return $matches[0];
            }
            // Recurse so nested {{> ...}} inside the inlined block also resolve.
            return $this->resolvePartials($resolved, $workflow, $depth + 1, $visited);
        }, $template);
    }
    
    /**
     * Resolve a single partial name to its content, or null if not found.
     */
    private function resolvePartialName(string $name, array $workflow): ?string {
        // Namespaced: command.X / command.$relatedCommands
        if (str_starts_with($name, 'command.')) {
            $cmd = substr($name, strlen('command.'));
            
            if ($cmd === '$relatedCommands') {
                $list = $workflow['relatedCommands'] ?? [];
                if (!is_array($list) || empty($list)) {
                    error_log('[WorkflowManager] {{> command.$relatedCommands}} used but workflow has no relatedCommands');
                    return '';
                }
                $blocks = [];
                foreach ($list as $cmdName) {
                    if (!is_string($cmdName)) { continue; }
                    $help = $this->resolveHelpForCommand($cmdName);
                    if ($help === null) {
                        $blocks[] = "### **{$cmdName}**\n\n_(help data unavailable)_";
                        continue;
                    }
                    $blocks[] = $this->formatCommandFull($cmdName, $help, true);
                }
                return implode("\n\n---\n\n", $blocks);
            }
            
            $help = $this->resolveHelpForCommand($cmd);
            if ($help === null) {
                error_log("[WorkflowManager] {{> command.{$cmd}}} — no help data found");
                return null;
            }
            return $this->formatCommandFull($cmd, $help, true);
        }
        
        // File-backed: pin.X, warning.X, example.X, or bare blockname
        $path = $this->partialFilePath($name);
        if ($path === null) {
            error_log("[WorkflowManager] {{> {$name}}} — invalid partial name");
            return null;
        }
        if (!file_exists($path)) {
            error_log("[WorkflowManager] {{> {$name}}} — file not found at {$path}");
            return null;
        }
        $content = @file_get_contents($path);
        return $content === false ? null : rtrim($content, "\r\n");
    }
    
    /**
     * Map a partial name to its on-disk file path.
     * Returns null for names that don't map to a file (e.g. command.* — handled separately).
     */
    private function partialFilePath(string $name): ?string {
        $base = $this->workflowsBasePath;
        if (str_starts_with($name, 'pin.')) {
            return $base . '/pins/' . substr($name, 4) . '.md';
        }
        if (str_starts_with($name, 'warning.')) {
            return $base . '/warnings/' . substr($name, 8) . '.md';
        }
        if (str_starts_with($name, 'example.')) {
            return $base . '/examples/' . substr($name, 8) . '.md';
        }
        if (str_contains($name, '.')) {
            // Unknown namespace
            return null;
        }
        return $base . '/blocks/' . $name . '.md';
    }
    
    /**
     * Resolve $each loops in params
     * Used for generating arrays from fetched data (data.langData.languages) or param values
     *
     * An object that carries `$if` (a condition, read as a step's) is kept without it when the condition holds, and
     * left out of its list or object when it does not: a template's menu holds its theme switch only when dark mode
     * is chosen.
     */
    private function resolveEachLoops(array $params, array $context): array {
        $wasList = array_is_list($params);
        $dropped = false;
        foreach ($params as $key => $value) {
            if (is_array($value) && array_key_exists('$if', $value)) {
                if (!is_string($value['$if']) || !$this->evaluateStepCondition($value['$if'], $context)) {
                    unset($params[$key]);
                    $dropped = true;
                    continue;
                }
                unset($value['$if']);
                $params[$key] = $value;
            }
            if (is_array($value)) {
                // Check if this is an $each loop
                if (isset($value['$each'])) {
                    $source = $this->resolveStepParamValue($value['$each'], $context);
                    $template = $value['$item'] ?? [];
                    
                    // If source is a comma-separated string, convert to array
                    if (is_string($source) && !empty($source)) {
                        $source = array_map('trim', explode(',', $source));
                    }
                    
                    if (is_array($source) && !empty($source)) {
                        $params[$key] = [];
                        foreach ($source as $itemKey => $itemValue) {
                            // Create context with $key and $value
                            $itemContext = array_merge($context, [
                                '$key' => $itemKey,
                                '$value' => $itemValue
                            ]);
                            $resolvedItem = $this->resolveStepParams($template, $itemContext);
                            // Apply $each recursively for nested loops and resolve value filters
                            $resolvedItem = $this->resolveEachLoops($resolvedItem, $itemContext);
                            $params[$key][] = $resolvedItem;
                        }
                    } else {
                        // Source not resolved yet (might depend on AI output)
                        // Leave the $each intact for late resolution
                        $params[$key] = $value;
                    }
                } else {
                    // Recurse into nested objects
                    $params[$key] = $this->resolveEachLoops($value, $context);
                }
            }
        }
        
        return $dropped && $wasList ? array_values($params) : $params;
    }
}
