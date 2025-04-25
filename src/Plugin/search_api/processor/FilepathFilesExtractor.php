<?php

namespace Drupal\search_api_filepath_attachments\Plugin\search_api\processor;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\search_api_attachments\Plugin\search_api\processor\FilesExtractor;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\StreamWrapper\StreamWrapperInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
use Drupal\file\Entity\File;
use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorProperty;
use Drupal\search_api\Utility\FieldsHelperInterface;
use Drupal\search_api_attachments\ExtractFileValidator;
use Drupal\search_api_attachments\TextExtractorPluginManager;
use GuzzleHttp\Psr7\StreamWrapper;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides filepath fields file processor
 * 
 * @SearchApiProcessor(
 *   id = "filepath_file_attachments",
 *   label = @Translation("Filepath file attachments"),
 *   description = @Translation("Adds the filepath file attachments content to the indexed data."),
 *   stages = {
 *     "add_properties" = 0,
 *   }
 * )
 */
class FilepathFilesExtractor extends FilesExtractor
{

    /**
     * Prefix of the properties provided by this module.
     */
    const SAFA_PREFIX = 'safa_';

    /**
     * Stream wrapper manager service.
     *
     * @var \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface
     */
    protected $stream_wrapper_manager;

    /**
     * {@inheritdoc}
     */
    public function __construct(
        array $configuration,
        $plugin_id,
        array $plugin_definition,
        TextExtractorPluginManager $text_extractor_plugin_manager,
        ConfigFactoryInterface $config_factory,
        EntityTypeManagerInterface $entity_type_manager,
        KeyValueFactoryInterface $key_value,
        ModuleHandlerInterface $module_handler,
        FieldsHelperInterface $field_helper,
        ExtractFileValidator $extractFileValidator,
        LoggerInterface $logger,
        StreamWrapperManager $stream_wrapper_manager
    ) {
        parent::__construct(
            $configuration,
            $plugin_id,
            $plugin_definition,
            $text_extractor_plugin_manager,
            $config_factory,
            $entity_type_manager,
            $key_value,
            $module_handler,
            $field_helper,
            $extractFileValidator,
            $logger
        );
        $this->stream_wrapper_manager = $stream_wrapper_manager;
    }

    /**
     * {@inheritdoc}
     */
    public static function create(
        ContainerInterface $container,
        array $configuration,
        $plugin_id,
        $plugin_definition
    ) {
        return new static(
            $configuration,
            $plugin_id,
            $plugin_definition,
            $container->get('plugin.manager.search_api_attachments.text_extractor'),
            $container->get('config.factory'),
            $container->get('entity_type.manager'),
            $container->get('keyvalue'),
            $container->get('module_handler'),
            $container->get('search_api.fields_helper'),
            $container->get('search_api_attachments.extract_file_validator'),
            $container->get('logger.channel.search_api_attachments'),
            $container->get('stream_wrapper_manager')
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getPropertyDefinitions(?DatasourceInterface $datasource = NULL)
    {
        $properties = [];

        if (!$datasource) {
            // Add properties for all index available filepath fields.
            foreach ($this->getFilepathFields() as $field_name => $label) {
                $definition = [
                    'label' => $this->t('Search api file attachments: @label', ['@label' => $label]),
                    'description' => $this->t('Search api file attachments: @label', ['@label' => $label]),
                    'type' => 'string',
                    'processor_id' => $this->getPluginId(),
                ];
                $properties[static::SAFA_PREFIX . $field_name] = new ProcessorProperty($definition);
            }
        }

        return $properties;
    }

    /**
     * {@inheritdoc}
     */
    public function addFieldValues(ItemInterface $item)
    {
        $config = $this->configFactory->get(static::CONFIGNAME);
        $extractor_plugin_id = $config->get('extraction_method');
        $this->configuration['read_text_files_directly'] = $config->get('read_text_files_directly');

        if ($extractor_plugin_id != '') {
            $configuration = $config->get($extractor_plugin_id . '_configuration');
            $extractor_plugin = $this->textExtractorPluginManager->createInstance($extractor_plugin_id, $configuration);
            $entity = $item->getOriginalObject()->getValue();
            if (!$entity instanceof EntityInterface) {
                return;
            }

            foreach ($this->getFilepathFields() as $field_name => $value) {
                $property_path = static::SAFA_PREFIX . $field_name;

                // A way to load $field.
                foreach ($this->fieldHelper->filterForPropertyPath($item->getFields(), NULL, $property_path) as $field) {
                    if ($entity instanceof FieldableEntityInterface && $entity->hasField($field_name)) {
                        if (!$entity->get($field_name)->isEmpty()) {
                            $field_data = $entity->get($field_name)->getValue();
                            if (!empty($field_data)) {
                                foreach ($field_data as $field_item) {
                                    if (isset($field_item['uri'])) {
                                        $uri = $field_item['uri'];

                                        $pattern = "/sites\/.*\/files/";
                                        if (preg_match($pattern, $uri, $matches, PREG_OFFSET_CAPTURE)) {
                                            $file_directory_path = $matches[0][0];
                                            $index = $matches[0][1];

                                            $local_wrappers = $this->stream_wrapper_manager->getWrappers(StreamWrapperInterface::LOCAL);
                                            // Reversing the array to prioritize the custom stream wrappers.
                                            // @TODO: Find a better way than reversing the array
                                            foreach (array_reverse(array_keys($local_wrappers)) as $wrapper) {
                                                /**
                                                 * @var namespace Drupal\Core\StreamWrapper\LocalStream $stream_wrapper
                                                 */
                                                $stream_wrapper = $this->stream_wrapper_manager->getViaScheme($wrapper);
                                                $stream_directory_path = $stream_wrapper->getDirectoryPath();

                                                if ($stream_directory_path == $file_directory_path) {
                                                    $uri = $wrapper . '://' . substr($uri, $index + strlen($file_directory_path) + 1);
                                                    break;
                                                }
                                            }

                                            $files = $this->entityTypeManager
                                                ->getStorage('file')
                                                ->loadByProperties(['uri' => $uri]);

                                            $file = $files ? reset($files) : NULL;

                                            if (!$file) {
                                                \Drupal::logger('search_api_filepath_attachments')->debug('File not in db, creating new file: @value', ['@value' => $uri]);

                                                $file = File::create([
                                                    'uri' => $uri,
                                                    'status' => 1,
                                                ]);

                                                $file->save();
                                            }

                                            $extraction = '';
                                            if ($this->isFileIndexable($file, $item, $field_name)) {
                                                $extraction .= $this->extractOrGetFromCache($entity, $file, $extractor_plugin);
                                            }

                                            if (!empty($extraction)) {
                                                $field->addValue($extraction);
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Get the filepath fields of indexed bundles.
     * 
     * @return array
     *   An array of filepath field with field name as key and label as value.
     */
    protected function getFilepathFields()
    {
        $file_elements = [];

        // Retrieve filepath fields of indexed bundles.
        foreach ($this->getIndex()->getDatasources() as $datasource) {
            foreach ($datasource->getPropertyDefinitions() as $property) {
                if ($property instanceof FieldDefinitionInterface) {
                    if ($property->getType() == 'link') {
                        $file_elements[$property->getName()] = $property->getLabel();
                    }
                }
            }
        }

        return $file_elements;
    }
}
