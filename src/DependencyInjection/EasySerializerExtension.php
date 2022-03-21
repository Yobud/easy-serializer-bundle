<?php


namespace Yobud\Bundle\EasySerializerBundle\DependencyInjection;


use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\Finder\Finder;

class EasySerializerExtension extends Extension implements PrependExtensionInterface
{
    protected $container;

    public function load(array $configs, ContainerBuilder $container)
    {
        $serializerLoaders = [];

        $container = $this->container;
        $chainLoader = $container->getDefinition('serializer.mapping.chain_loader');

        /** @var Definition $serializationContextBuilder */
        $serializationContextBuilder = $container->getDefinition('api_platform.serializer.context_builder');
        $classMetadataFactory = $container->getDefinition('serializer.mapping.class_metadata_factory');
        $expressionLanguage = $container->getDefinition('security.expression_language');
        $authorizationChecker = $container->getDefinition('security.authorization_checker');
        $serializationContextBuilder->setClass('Yobud\Bundle\EasySerializerBundle\Serializer\SerializerContextBuilder');
        $serializationContextBuilder->addArgument($classMetadataFactory);
        $serializationContextBuilder->addArgument($expressionLanguage);
        $serializationContextBuilder->addArgument($authorizationChecker);

        $fileRecorder = function ($extension, $path) use (&$serializerLoaders, $container) {
            $definition = new Definition(\in_array($extension, ['yaml', 'yml']) ? 'Yobud\Bundle\EasySerializerBundle\Serializer\Mapping\Loader\YamlFileLoader' : 'Symfony\Component\Serializer\Mapping\Loader\XmlFileLoader', [$path]);
            $definition->setPublic(false);

            $serializerLoaders[] = $definition;
        };

        foreach ($container->getParameter('kernel.bundles_metadata') as $bundle) {
            $configDir = is_dir($bundle['path'] . '/Resources/config') ? $bundle['path'] . '/Resources/config' : $bundle['path'] . '/config';

            if ($container->fileExists($dir = $configDir . '/easy-serializer', '/^$/')) {
                $this->registerMappingFilesFromDir($dir, $fileRecorder);
            }
        }

        $projectDir = $container->getParameter('kernel.project_dir');
        if ($container->fileExists($dir = $projectDir . '/config/easy-serializer', '/^$/')) {
            $this->registerMappingFilesFromDir($dir, $fileRecorder);
        }


        // Add our loader to already set ones
        $serializerLoaders = array_merge($chainLoader->getArgument(0), $serializerLoaders);
        $chainLoader->replaceArgument(0, $serializerLoaders);
    }

    private function registerMappingFilesFromDir(string $dir, callable $fileRecorder)
    {
        foreach (Finder::create()->followLinks()->files()->in($dir)->name('/\.(xml|ya?ml)$/')->sortByName() as $file) {
            $fileRecorder($file->getExtension(), $file->getRealPath());
        }
    }


    public function getNamespace()
    {
        return 'Yobud\Bundle\EasySerializer';
    }

    public function getXsdValidationBasePath()
    {
        return '';
    }

    public function getAlias(): string
    {
        return 'easy_serializer';
    }

    public function prepend(ContainerBuilder $container)
    {
        $this->container = $container;
    }
}
