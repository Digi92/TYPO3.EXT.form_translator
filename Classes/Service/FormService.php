<?php

declare(strict_types=1);

namespace R3H6\FormTranslator\Service;

use R3H6\FormTranslator\Facade\FormPersistenceManagerInterface;
use R3H6\FormTranslator\Parser\FormDefinitionLabelsParser;
use R3H6\FormTranslator\Translation\Dto\Typo3Language;
use R3H6\FormTranslator\Translation\Item;
use R3H6\FormTranslator\Translation\ItemCollection;
use R3H6\FormTranslator\Utility\PathUtility;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Localization\LocalizationFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Form\Mvc\Configuration\ConfigurationManagerInterface as FormConfigurationManagerInterface;

class FormService
{
    public const TRANSLATION_FILE_KEY = 99;

    public function __construct(
        protected readonly FormDefinitionLabelsParser $formDefinitionLabelsParser,
        protected readonly LocalizationFactory $localizationFactory,
        protected readonly FormPersistenceManagerInterface $formPersistenceManager,
        protected readonly ConfigurationManagerInterface $configurationManager,
        protected readonly FormConfigurationManagerInterface $extFormConfigurationManager,
        protected string $locallangPath,
    ) {}

    public function getItems(string $persistenceIdentifier, Typo3Language $language): ItemCollection
    {
        $items = $this->extractLabels($persistenceIdentifier);

        $this->getTranslation($items, $persistenceIdentifier, $language);

        $this->setPlaceholderWithSourceTranslations($items, $persistenceIdentifier, $language);

        return $items;
    }

    /**
     * Set placeholder texts on items that have no translation yet.
     * Loads the global translation files from the form framework's prototype configuration
     * and resolves matching translations following the TYPO3 lookup order.
     * This allows editors to see the original/default translation as a reference
     * while working on their own translations.
     *
     * @param ItemCollection $items Collection of translation items to enrich with placeholders
     * @param string $persistenceIdentifier Form persistence identifier used to load the form definition
     * @param Typo3Language $language Target language to resolve translations for
     * @return ItemCollection The enriched item collection
     */
    protected function setPlaceholderWithSourceTranslations(ItemCollection &$items, string $persistenceIdentifier, Typo3Language $language): ItemCollection
    {
        $form = $this->parseForm($persistenceIdentifier);
        if (!isset($form['identifier'])) {
            return $items;
        }

        $formId = $form['identifier'];
        $lang = $language->getTypo3Language();


        $translationFiles = [];
        $localLanguage = [];

        // Try to load global form translations
        try {
            /** @var FormConfigurationManagerInterface $formConfigurationManager */
            $formConfigurationManager = GeneralUtility::makeInstance(FormConfigurationManagerInterface::class);
            $yamlConfiguration = $formConfigurationManager->getConfiguration('YamlSettings', 'form');
            $translationFiles = $yamlConfiguration['prototypes']['standard']['formElementsDefinition']['Form']['renderingOptions']['translation']['translationFiles'] ?? [];
        } catch (\Throwable) {
        }

        foreach ($translationFiles as $translationFile) {
            $localLanguage = array_replace_recursive(
                $localLanguage,
                $this->localizationFactory->getParsedData($translationFile, $lang)
            );
        }

        // Default language should be en and if the transaltion file has not set the en locale in the file name all transaltions a given as "default" array key and not en
        if (!empty($localLanguage['default']) &&
            empty($localLanguage['en'])
        ) {
            $localLanguage['en'] = $localLanguage['default'];
        }

        $translations = $localLanguage[$lang] ?? [];
        if ($translations === []) {
            return $items;
        }

        $typeMap = $this->buildElementTypeMap($form);

        // --- Resolve placeholders ---
        //
        // TYPO3 lookup order (first match wins):
        //
        // Element properties:
        //   1. contactForm.element.LastName.properties.label       (form-specific)
        //   2. Form.element.LastName.properties.label              (renderingOptions)
        //   3. element.LastName.properties.label                   (global by name)
        //   4. contactForm.element.Text.properties.label           (form-specific by type)
        //   5. element.Text.properties.label                       (global by type)
        //
        // Validators:
        //   1. contactForm.validation.error.LastName.1221560910    (form + element)
        //   2. validation.error.LastName.1221560910                (element only)
        //   3. contactForm.validation.error.1221560910             (form + code only)
        //   4. validation.error.1221560910                         (code only)
        //
        // Finishers:
        //   1. contactForm.finisher.EmailToSender.subject          (form-specific)
        //   2. finisher.EmailToSender.subject                      (global)
        foreach ($items as $item) {
            if ($item->getTarget() !== '' && $item->getTarget() !== null) {
                continue;
            }

            $id = $item->getIdentifier();
            $global = str_replace($formId . '.', '', $id);

            // Lookup order per category – first match wins.
            // See TYPO3 docs: Frontend rendering > Translation of form element properties
            $candidates = [];

            // Element properties, e.g. "element.LastName.properties.label"
            // Only matches when the name after "element." is an actual form element.
            if (preg_match('~^element\.([^.]+)\.(.+)$~', $global, $m) && isset($typeMap[$m[1]])) {
                $elementName = $m[1];
                $property = $m[2];
                $elementType = $typeMap[$elementName] ?? null;

                $candidates[] = $formId . '.element.' . $elementName . '.' . $property;  // contactForm.element.LastName.properties.label
                $candidates[] = 'Form.element.' . $elementName . '.' . $property;        // Form.element.LastName.properties.label
                $candidates[] = 'element.' . $elementName . '.' . $property;             // element.LastName.properties.label
                if ($elementType !== null) {
                    $candidates[] = $formId . '.element.' . $elementType . '.' . $property;  // contactForm.element.Text.properties.label
                    $candidates[] = 'element.' . $elementType . '.' . $property;             // element.Text.properties.label
                }

                // Validators, e.g. "validation.error.LastName.1221560910"
            } elseif (preg_match('~^validation\.error\.([^.]+)\.(\d+)$~', $global, $m)) {
                $elementName = $m[1];
                $errorCode = $m[2];

                $candidates[] = $formId . '.validation.error.' . $elementName . '.' . $errorCode;  // contactForm.validation.error.LastName.1221560910
                $candidates[] = 'validation.error.' . $elementName . '.' . $errorCode;              // validation.error.LastName.1221560910
                $candidates[] = $formId . '.validation.error.' . $errorCode;                        // contactForm.validation.error.1221560910
                $candidates[] = 'validation.error.' . $errorCode;                                   // validation.error.1221560910

                // Finishers, renderingOptions, or any other key
            } else {
                $candidates[] = $id;                                          // contactForm.finisher.EmailToSender.subject
                $candidates[] = str_replace($formId . '.', 'Form.', $id);    // Form.finisher.EmailToSender.subject
                $candidates[] = $global;                                      // finisher.EmailToSender.subject
            }

            // First match wins
            foreach ($candidates as $key) {
                $value = $translations[$key][0]['target'] ?? null;
                if (is_string($value) && $value !== '') {
                    $item->setPlaceholder($value);
                    break;
                }
            }
        }

        return $items;
    }

    /**
     * Build a flat lookup map from the nested form definition: elementIdentifier → elementType.
     *
     * Example:
     *   | elementIdentifier | elementType  |
     *   |-------------------|--------------|
     *   | LastName          | Text         |
     *   | gender-1          | SingleSelect |
     *   | message-1         | Textarea     |
     *   | consent-1         | Checkbox     |
     *
     * @return array<string, string>
     */
    private function buildElementTypeMap(array $form): array
    {
        $map = [];

        $queue = $form['renderables'] ?? [];

        while ($queue !== []) {
            $el = array_shift($queue);
            if (isset($el['identifier'], $el['type'])) {
                $map[$el['identifier']] = $el['type'];
            }
            foreach ($el['renderables'] ?? [] as $child) {
                $queue[] = $child;
            }
        }

        return $map;
    }

    public function listForms(): array
    {
        return $this->formPersistenceManager->listForms();
    }

    public function extractLabels(string $persistenceIdentifier): ItemCollection
    {
        $items = new ItemCollection();
        $form = $this->parseForm($persistenceIdentifier);
        foreach ($this->formDefinitionLabelsParser->parse($form) as $identifier => $original) {
            $item = new Item($identifier);
            $item->setOriginal($original);
            $items->addItem($item);
        }
        return $items;
    }

    public function getTranslation(ItemCollection $items, string $persistenceIdentifier, Typo3Language $language): ItemCollection
    {
        $form = $this->parseForm($persistenceIdentifier);
        $dir = Environment::getPublicPath();
        $localLanguage = [];
        $translationFiles = $form['renderingOptions']['translation']['translationFiles'] ?? [];
        foreach ($translationFiles as $translationFile) {
            $path = PathUtility::makeAbsolute($translationFile, $dir);
            $localLanguage = array_replace_recursive($localLanguage, $this->localizationFactory->getParsedData($path, $language->getTypo3Language()));
        }

        if (array_key_exists($language->getTypo3Language(), $localLanguage) && is_array($localLanguage[$language->getTypo3Language()])) {
            foreach ($localLanguage[$language->getTypo3Language()] as $identifier => $values) {
                $item = $items->getItem($identifier) ?? new Item($identifier);
                $item->setSource($values[0]['source']);
                $item->setTarget($values[0]['target']);
                $items->addItem($item);
            }
        }

        return $items;
    }

    public function getTitle(string $persistenceIdentifier): string
    {
        $form = $this->parseForm($persistenceIdentifier);
        return $form['label'] ?? 'Undefined';
    }

    public function parseForm(string $persistenceIdentifier): array
    {
        $path = PathUtility::makeAbsolute($persistenceIdentifier);
        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException('Could not read file', 1641680505885);
        }
        return Yaml::parse($content);
    }

    public function addTranslationFile(string $persistenceIdentifier, string $locallangFile): void
    {
        // Normalize locallang path
        $locallangFile = (str_starts_with($locallangFile, Environment::getExtensionsPath())) ?
            'EXT:' . ltrim(str_replace(Environment::getExtensionsPath(), '', $locallangFile), '/') :
            ltrim(str_replace(Environment::getPublicPath(), '', $locallangFile), '/');

        $form = $this->parseForm($persistenceIdentifier);
        $translationFiles = $form['renderingOptions']['translation']['translationFiles'] ?? [];
        if (in_array($locallangFile, $translationFiles)) {
            return;
        }
        $form['renderingOptions']['translation']['translationFiles'][self::TRANSLATION_FILE_KEY] = $locallangFile;
        $yaml = Yaml::dump($form, 99, 2);

        $formPath = PathUtility::makeAbsolute($persistenceIdentifier);
        GeneralUtility::writeFile($formPath, $yaml);
    }

    public function getLocallangFileFromPersistenceIdentifier(string $persistenceIdentifier): string
    {
        $form = $this->parseForm($persistenceIdentifier);
        $translationFile = $form['renderingOptions']['translation']['translationFiles'][self::TRANSLATION_FILE_KEY] ?? null;
        if ($translationFile !== null) {
            return PathUtility::makeAbsolute($translationFile, Environment::getPublicPath());
        }

        $formPath = PathUtility::makeAbsolute($persistenceIdentifier);
        $storage = PathUtility::makeAbsolute($this->locallangPath, dirname($formPath));
        if ($this->isWritable($persistenceIdentifier) === false) {
            $storageIdentifier = (string)array_key_first($this->formPersistenceManager->getAccessibleFormStorageFolders());
            $storage = PathUtility::makeAbsolute($storageIdentifier);
        }

        return rtrim($storage, '/') . '/' . basename($formPath, '.form.yaml') . '.xlf';
    }

    public function isWritable(string $persistenceIdentifier): bool
    {
        if (Environment::getContext()->isDevelopment()) {
            return true;
        }
        foreach ($this->formPersistenceManager->listForms() as $form) {
            if ($form['persistenceIdentifier'] === $persistenceIdentifier && $form['readOnly'] === false) {
                return true;
            }
        }
        return false;
    }
}
