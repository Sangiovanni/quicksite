<?php 
require_once SECURE_FOLDER_PATH . '/src/classes/TrimParameters.php';
$trimParameters = new TrimParameters();
require_once SECURE_FOLDER_PATH . '/src/classes/Translator.php';
$translator = new Translator($trimParameters->lang());
$footerItems = require_once SECURE_FOLDER_PATH . '/templates/model/footer.php';

$sep = '';
if(MULTILINGUAL_SUPPORT){
    $sep = $trimParameters->lang() . DIRECTORY_SEPARATOR;
}
?>      
        <div class="footer">
            <div class="footer-block">
                <?php foreach($footerItems as $item): ?>
                    <?php
                        // 1. Determine the URL: Check if absoluteLink is set, otherwise construct the internal URL
                        if (!empty($item['absoluteLink'])) {
                            $url = $item['absoluteLink'];
                        } else {
                            // Assumes $sep and $trimParameters->lang() are defined
                            $url = BASE_URL . $sep  . $item['path'];
                        }
                        // 2. Determine the target attribute (e.g., target="_blank" for external links)
                        $target = !empty($item['target']) ? 'target="' . $item['target'] . '"' : '';
                    ?>
                    <div>
                        <a class="footer-option" href="<?php echo htmlspecialchars($url); ?>" <?php echo $target; ?> <?php if($item['target'] == "_blank"){?>  rel="noopener noreferrer"<?php }?>>
                            <?php echo htmlspecialchars($translator->translate($item['label'])); ?>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if(MULTILINGUAL_SUPPORT){?>
            <div class='footer-block'>
            <?php foreach (CONFIG['LANGUAGES_NAME'] as $langCode => $langName):?>
                <div>
                    <a class='footer-option' href=" <?php echo htmlspecialchars($trimParameters->samePageUrl($langCode)); ?>" <?php if($item['target'] == "_blank"){?>  rel="noopener noreferrer"<?php }?>>
                        <?php echo htmlspecialchars($langName); ?>
                    </a>
                </div>
            <?php endforeach;
                }
            ?>
                </div>
            </div>
        <script src="<?php echo BASE_URL;?>/scripts/scripts.js"></script>
    