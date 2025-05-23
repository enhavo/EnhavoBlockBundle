<?php

/*
 * This file is part of the enhavo package.
 *
 * (c) WE ARE INDEED GmbH
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Enhavo\Bundle\BlockBundle\Maker;

use Enhavo\Bundle\AppBundle\Maker\MakerUtil;
use Enhavo\Bundle\AppBundle\Util\NameTransformer;
use Enhavo\Bundle\BlockBundle\Maker\Generator\DoctrineOrmYaml;
use Enhavo\Bundle\BlockBundle\Maker\Generator\FormType;
use Enhavo\Bundle\BlockBundle\Maker\Generator\PhpClass;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Yaml\Yaml;

class BlockDefinition
{
    private BundleInterface $namespace;
    private string $name;
    private NameTransformer $nameTransformer;
    private ?BundleInterface $bundle = null;
    private array $subDirectories;
    private string $path;

    /**
     * @throws \Exception
     */
    public function __construct(
        private MakerUtil $util,
        private KernelInterface $kernel,
        private Filesystem $filesystem,
        private array $config,
    ) {
        foreach ($config as $key => $value) {
            $this->name = $key;
            $this->config = $value;
            break;
        }

        $this->nameTransformer = new NameTransformer();

        if (str_ends_with($this->getNamespace(), 'Bundle')) {
            $this->bundle = $this->kernel->getBundle($this->getNamespace());
            $this->setNamespace($this->bundle->getNamespace());
        }

        $nameParts = explode('/', $this->getName());
        $this->name = array_pop($nameParts);
        $this->subDirectories = $nameParts;
        $this->path = str_replace('\\', '/', $this->getNamespace());

        $this->loadTemplates();
    }

    private function loadTemplates(): void
    {
        foreach ($this->getProperties() as $key => $property) {
            if (isset($property['template'])) {
                $path = $this->getTemplatePath($property['template']);
                if (null === $path) {
                    throw new \Exception(sprintf('Cant find template "%s"', $property['template']));
                }
                $config = Yaml::parseFile($path);
                $this->config['properties'][$key] = $this->deepMerge($config, $this->config['properties'][$key]);
                unset($this->config['properties'][$key]['template']);
            }

            $classUse = $this->extractFromProperty($key, ['type_options', 'use']);
            $this->addUse($classUse);
            $formUse = $this->extractFromProperty($key, ['form', 'use']);
            $this->addFormUse($formUse);
        }
    }

    private function extractFromProperty(string $key, array $path)
    {
        $property = $this->config['properties'][$key];

        foreach ($path as $part) {
            $property = $property[$part] ?? [];
        }

        return $property;
    }

    public function getDoctrineORMFilePath(): string
    {
        $subDirectory = $this->subDirectories ? implode('.', $this->subDirectories).'.' : '';
        $filename = sprintf('%s%s.orm.yml', $subDirectory, $this->nameTransformer->camelCase($this->name));

        if ($this->bundle) {
            return sprintf('src/%s/Resources/config/doctrine/%s', $this->path, $filename);
        }

        return sprintf('%s/config/doctrine/%s', $this->util->getProjectPath(), $filename);
    }

    public function getEntityFilePath(): string
    {
        $subDirectory = $this->subDirectories ? implode('/', $this->subDirectories).'/' : '';
        $filename = sprintf('%s%s.php', $subDirectory, $this->nameTransformer->camelCase($this->name));

        if ($this->bundle) {
            return sprintf('src/%s/Entity/%s', $this->path, $filename);
        }

        return sprintf('%s/src/Entity/%s', $this->util->getProjectPath(), $filename);
    }

    public function getEntityNamespace(): string
    {
        $subDirectory = $this->subDirectories ? '\\'.implode('\\', $this->subDirectories) : '';

        return sprintf('%s\\Entity%s', $this->getNamespace(), $subDirectory);
    }

    public function getFormTypeFilePath(): string
    {
        $subDirectory = $this->subDirectories ? implode('/', $this->subDirectories).'/' : '';
        $filename = sprintf('%s%sType.php', $subDirectory, $this->nameTransformer->camelCase($this->name));

        if ($this->bundle) {
            return sprintf('src/%s/Form/Type/%s', $this->path, $filename);
        }

        return sprintf('%s/src/Form/Type/%s', $this->util->getProjectPath(), $filename);
    }

    public function getTemplateFilePath(): string
    {
        if ($this->bundle) {
            return sprintf('src/%s/Resources/views/%s', $this->path, $this->getTemplateFileName());
        }

        return sprintf('%s/templates/%s', $this->util->getProjectPath(), $this->getTemplateFileName());
    }

    public function getTemplateFileName(): string
    {
        return sprintf('theme/block/%s.html.twig', str_replace('-block', '', $this->getKebabName()));
    }

    public function getTypeFilePath(): string
    {
        if ($this->bundle) {
            return sprintf('src/%s/Block/%sType.php', $this->path, $this->name);
        }

        return sprintf('%s/src/Block/%sType.php', $this->util->getProjectPath(), $this->name);
    }

    public function getFormNamespace(): string
    {
        $subDirectory = $this->subDirectories ? '\\'.implode('\\', $this->subDirectories) : '';

        return sprintf('%s\\Form\\Type%s', $this->getNamespace(), $subDirectory);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSnakeName(): string
    {
        return str_replace('_block', '', $this->nameTransformer->snakeCase($this->getName()));
    }

    public function getCamelName(): string
    {
        return $this->nameTransformer->camelCase($this->getName());
    }

    public function getKebabName(): string
    {
        return $this->nameTransformer->kebabCase($this->getName());
    }

    public function getFormTypeName(): string
    {
        return sprintf('%s%s', $this->nameTransformer->camelCase($this->name), 'Type');
    }

    public function getTranslationDomain(): ?string
    {
        return $this->bundle?->getName();
    }

    public function getApplicationName(): string
    {
        if ($this->bundle) {
            $bundleName = $this->util->getBundleNameWithoutPostfix($this->bundle->getName());

            return $this->nameTransformer->snakeCase($bundleName);
        }

        return $this->nameTransformer->snakeCase(explode('\\', $this->getNamespace()));
    }

    public function createEntityPhpClass(): PhpClass
    {
        $class = new PhpClass($this->getEntityNamespace(), $this->getName(), $this->getImplements(), $this->getUse(), $this->getTraits(), $this->getProperties());
        $class->generateConstructor();
        $class->generateGettersSetters();
        $class->generateAddersRemovers();

        return $class;
    }

    /**
     * @throws \Exception
     */
    public function getClasses(): array
    {
        $definitions = [];
        $classes = $this->getConfig('classes', []);
        foreach ($classes as $key => $config) {
            $definition = new BlockDefinition($this->util, $this->kernel, $this->filesystem, [
                $key => $config,
            ]);
            $definition->addUse(sprintf('%s\%s', $this->getEntityNamespace(), $this->getName()));
            $this->addUse(sprintf('%s\%s', $definition->getEntityNamespace(), $definition->getName()));

            $definitions[] = $definition;
        }

        return $definitions;
    }

    public function getLabel(): string
    {
        return $this->getConfig('label') ?? $this->getCamelName();
    }

    public function getImplements(): ?string
    {
        return $this->getConfig('implements', null);
    }

    public function createFormTypePhpClass(): PhpClass
    {
        return new PhpClass($this->getFormNamespace(), $this->getFormTypeName(), null, $this->getFormUse(), [], []);
    }

    public function createDoctrineOrmYaml(): DoctrineOrmYaml
    {
        $applicationName = $this->nameTransformer->snakeCase($this->getApplicationName());
        $applicationName = str_replace('enhavo_', '', $applicationName); // special case for enhavo
        $tableName = sprintf('%s_%s', $applicationName, $this->nameTransformer->snakeCase($this->getName()));

        return new DoctrineOrmYaml($tableName, $this->getProperties());
    }

    public function getFormType(): FormType
    {
        $blockPrefix = sprintf('%s_%s', $this->nameTransformer->snakeCase($this->getApplicationName()), $this->nameTransformer->snakeCase($this->getName()));

        return new FormType($blockPrefix, $this->getProperties());
    }

    public function getProperties(): array
    {
        return $this->config['properties'] ?? [];
    }

    public function getTraits(): array
    {
        return $this->config['traits'] ?? [];
    }

    public function getConfig(string $key, $default = null)
    {
        return $this->config[$key] ?? $default;
    }

    public function getNamespace()
    {
        return $this->namespace ?? $this->config['namespace'] ?? 'App';
    }

    public function setNamespace($namespace): void
    {
        $this->namespace = $namespace;
    }

    public function getGroupsString(): ?string
    {
        return isset($this->config['groups']) ? sprintf("[ '%s' ]\n", implode("', '", $this->config['groups'])) : null;
    }

    public function getBlockType(): ?bool
    {
        return $this->config['block_type'] ?? false;
    }

    public function addUse(mixed $use): void
    {
        if (!isset($this->config['use'])) {
            $this->config['use'] = [];
        }
        if (is_array($use)) {
            foreach ($use as $part) {
                $this->addUse($part);
            }
        } else {
            $this->config['use'][] = $use;
        }
    }

    public function addFormUse(mixed $use): void
    {
        if (!isset($this->config['form']['use'])) {
            $this->config['form']['use'] = [];
        }
        if (is_array($use)) {
            foreach ($use as $part) {
                $this->addFormUse($part);
            }
        } else {
            $this->config['form']['use'][] = $use;
        }
    }

    private function getUse(): array
    {
        if (isset($this->config['use'])) {
            return array_unique($this->config['use']) ?? [];
        }

        return [];
    }

    private function getFormUse(): array
    {
        return array_unique($this->config['form']['use'] ?? []);
    }

    private function deepMerge($array1, $array2)
    {
        foreach ($array2 as $key => $value) {
            if (is_array($value)) {
                $array1[$key] = $this->deepMerge($array1[$key] ?? [], $array2[$key]);
            } else {
                $array1[$key] = $value;
            }
        }

        return $array1;
    }

    private function getTemplatePath($template): ?string
    {
        $paths = [];
        $paths[] = sprintf('%s/%s.yaml', $this->util->getProjectPath(), $template);
        $paths[] = sprintf('%s/%s/%s.yaml', $this->util->getProjectPath(), 'config/block/templates', $template);
        $paths[] = sprintf('%s%s/%s.yaml', $this->util->getBundlePath('EnhavoBlockBundle'), 'Resources/block/templates', $template);
        foreach ($paths as $path) {
            if ($this->filesystem->exists($path)) {
                return $path;
            }
        }

        return null;
    }
}
