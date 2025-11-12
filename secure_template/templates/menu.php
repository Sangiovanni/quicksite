<?php 
require_once SECURE_FOLDER_PATH . '/src/classes/TrimParameters.php';
$trimParameters = new TrimParameters();
$lang = $trimParameters->lang();
require_once SECURE_FOLDER_PATH . '/src/classes/Translator.php';
$translator = new Translator($trimParameters->lang());

$menuItems = require_once SECURE_FOLDER_PATH . '/templates/model/menu.php';

$sep = '';
if(MULTILINGUAL_SUPPORT){
    $sep =  $lang . DIRECTORY_SEPARATOR;
}
?>


<div class="logo">
    <a href="<?php echo BASE_URL.DIRECTORY_SEPARATOR.$lang;?>">
        <img src="<?php echo BASE_URL;?>/pics/logo.png">
    </a>
</div>

<div class="menu">
    <?php foreach ($menuItems as $item): ?>
    <div class="menu-label">
        <?php
            if (!empty($item['absoluteLink'])) {
                $url = $item['absoluteLink'];
            } else {
                $url = BASE_URL . $sep  . $item['path'];
            }

            // 2. Determine the target attribute
            // Use the specified target or default to _self if not set
            $target = !empty($item['target']) ? 'target="' . $item['target'] . '"' : '';

            // 3. Output the link
        ?>
        <a href="<?php echo htmlspecialchars($url); ?>" <?php echo $target; ?> <?php if($item['target'] == "_blank"){?>  rel="noopener noreferrer"<?php }?>><?php echo htmlspecialchars($translator->translate($item['label'])); ?></a>
    </div>
<?php endforeach; ?>
</div>
