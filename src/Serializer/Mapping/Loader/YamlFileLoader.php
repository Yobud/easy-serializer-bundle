<?php

namespace Yobud\Bundle\EasySerializerBundle\Serializer\Mapping\Loader;

use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\OneToOne;
use Symfony\Component\Serializer\Exception\MappingException;
use Symfony\Component\Serializer\Mapping\AttributeMetadata;
use Symfony\Component\Serializer\Mapping\ClassMetadata;
use Symfony\Component\Serializer\Mapping\ClassMetadataInterface;
use Symfony\Component\Serializer\Mapping\Loader\FileLoader;
use Symfony\Component\Yaml\Parser;
use Symfony\Component\Yaml\Yaml;

/**
 * YAML File Loader.
 *
 * @author Jérémy Hubert <jeremyh.contact@gmail.com>
 */
class YamlFileLoader extends FileLoader
{
    protected $yamlParser;

    /**
     * An array of YAML class descriptions.
     *
     * @var array
     */
    protected $classes;

    /**
     * An array of ClassMetadata.
     *
     * @var array
     */
    protected $classesMetadata = [];


    /**
     * {@inheritdoc}
     * @throws \ReflectionException
     */
    public function loadClassMetadata(ClassMetadataInterface $classMetadata): bool
    {
        if (null === $this->classes) {
            $this->classes = $this->getClassesFromYaml();
        }

        if (!$this->classes) {
            return false;
        }

        if (!isset($this->classes[$classMetadata->getName()])) {
            return false;
        }

        $yaml = $this->classes[$classMetadata->getName()];

        // Store all security groups in a custom attribute metadata
        $allGroups = new AttributeMetadata('_allGroups');
        $allGroups->setIgnore(true);
        $classMetadata->addAttributeMetadata($allGroups);

        // Parse group ('item.normalization.get')
        foreach ($yaml as $group => $data) {
            $baseGroup = "{$classMetadata->getName()}:{$group}";

            if (isset($this->classesMetadata[$classMetadata->getName()])) {
                $classMetadata->merge($this->classesMetadata[$classMetadata->getName()]);
            }

            $this->classesMetadata[$classMetadata->getName()] = $classMetadata;
            foreach ($classMetadata->getAttributesMetadata() as $attributeMetadata) {
                if ($attributeMetadata->getName() === '_allGroups') {
                    $allGroups = $attributeMetadata;
                }
            }

            $this->processAttributes($allGroups, $data, $classMetadata->getName(), $baseGroup);
        }

//        if (isset($yaml['discriminator_map'])) {
//            if (!isset($yaml['discriminator_map']['type_property'])) {
//                throw new MappingException(sprintf('The "type_property" key must be set for the discriminator map of the class "%s" in "%s".', $classMetadata->getName(), $this->file));
//            }
//
//            if (!isset($yaml['discriminator_map']['mapping'])) {
//                throw new MappingException(sprintf('The "mapping" key must be set for the discriminator map of the class "%s" in "%s".', $classMetadata->getName(), $this->file));
//            }
//
//            $classMetadata->setClassDiscriminatorMapping(new ClassDiscriminatorMapping(
//                $yaml['discriminator_map']['type_property'],
//                $yaml['discriminator_map']['mapping']
//            ));
//        }

        return true;
    }

    /**
     * Return the names of the classes mapped in this file.
     *
     * @return string[] The classes names
     */
    public function getMappedClasses()
    {
        if (null === $this->classes) {
            $this->classes = $this->getClassesFromYaml();
        }

        return array_keys($this->classes);
    }

    private function getClassesFromYaml(): array
    {
        if (!stream_is_local($this->file)) {
            throw new MappingException(sprintf('This is not a local file "%s".', $this->file));
        }

        if (null === $this->yamlParser) {
            $this->yamlParser = new Parser();
        }

        $classes = $this->yamlParser->parseFile($this->file, Yaml::PARSE_CONSTANT);

        if (empty($classes)) {
            return [];
        }

        if (!\is_array($classes)) {
            throw new MappingException(sprintf('The file "%s" must contain a YAML array.', $this->file));
        }

        $allClasses = array_merge($classes, []);
        foreach ($classes as $className => $class) {
            // Parse group ('item.normalization.get')
            foreach ($class as $group => $data) {
                if (empty($data)) {
                    throw new MappingException(sprintf('The mapping described for group "%s" of entity "%s" in file "%s" should not be empty.', $group, $className, $this->file));
                }

                $allClasses = \array_merge_recursive($allClasses, $this->findClassInAttributes($data, $className, $group));
            }
        }

        return $allClasses;
    }

    /**
     * @throws \ReflectionException
     */
    private function processAttributes(AttributeMetadata $allGroups, array $attributes, $className, $baseGroup, string $conditions = null, AttributeMetadata $parentAttribute = null)
    {
        $securityPrefixes = [];
        // Each security leads to a new (sub)group ("App\Entity\EntityClass:item.normalization.get:is_granted('ROLE_ADMIN')")
        // For each attribute, detect the associated class if any
        // Split path
        foreach ($attributes as $attribute => $data) {
            if (!$data) {
                $data = [];
            }

            $previousPathElementClass = null;
            $securityPrefix = null;
            $pathElements = explode('.', $attribute);
            $length = count($pathElements);
            foreach ($pathElements as $elementIndex => $pathElement) {

                switch (true) {

                    // Deeper elements with same prefix require to pass given expression
                    case \str_starts_with($pathElement, '_security_'):
                        $prefix = \str_replace('_security', '', $pathElement);
                        $securityPrefixes[$prefix] = $conditions ? ("({$conditions}) and ({$data})") : $data;
                        break;

                    // All deeper elements require to pass given expression, and direct parent attribute too
                    case $pathElement === '_security':
                        $conditions = $conditions ? ("({$conditions}) and ({$data})") : $data;
                        $parentAttribute->addGroup("{$baseGroup}:{$conditions}");
                        $allGroups->addGroup("{$baseGroup}:{$conditions}");
                        break;

                    // Handle _max_depth reserved keyword
                    case \str_starts_with($pathElement, '_maxDepth'):
                        $parentAttribute->setMaxDepth($data);
                        break;

                    // Handle _serialized_name reserved keyword
                    case \str_starts_with($pathElement, '_serializedName'):
                        $parentAttribute->setSerializedName($data);
                        break;

                    // Handle _ignore reserved keyword
                    case \str_starts_with($pathElement, '_ignore'):
                        $parentAttribute->setIgnore($data);
                        break;

                    // All pathElements require $securityPrefix condition
                    case \str_starts_with($pathElement, '_'):
                        $securityPrefix = $pathElement;
                        break;

                    // process "normal" attribute
                    default:
                        // Get parent class for current attribute
                        if (!$previousPathElementClass) {
                            $previousPathElementClass = $className;
                        }

                        // Process path element as a class
                        if (!isset($this->classesMetadata[$previousPathElementClass])) {
                            $this->classes[$previousPathElementClass] = [$baseGroup => []];
                            $this->classesMetadata[$previousPathElementClass] = new ClassMetadata($previousPathElementClass);
                        }

                        $attributeMetadata = new AttributeMetadata($pathElement);

                        $group = $baseGroup . ($conditions ? ":{$conditions}" : '');
                        if ($securityPrefix) {
                            $group = $baseGroup . ($securityPrefixes[$securityPrefix] ? ":{$securityPrefixes[$securityPrefix]}" : '');
                        }

                        if ($elementIndex < $length - 1 || !isset($data['_security'])) {
                            $attributeMetadata->addGroup($group);
                            $allGroups->addGroup($group);
                        }

                        $newClassMetadata = new ClassMetadata($previousPathElementClass);
                        $newClassMetadata->addAttributeMetadata($attributeMetadata);

                        $this->classesMetadata[$previousPathElementClass]->merge($newClassMetadata);

                        $previousPathElementClass = $this->getPathElementClass($this->classesMetadata[$previousPathElementClass]->getReflectionClass(), $pathElement);

                        if ($elementIndex === $length - 1 && $previousPathElementClass) {
                            $this->processAttributes(
                                $allGroups,
                                $data,
                                $previousPathElementClass,
                                $baseGroup,
                                $securityPrefix ? $securityPrefixes[$securityPrefix] : $conditions,
                                $attributeMetadata
                            );
                        }
                }

            }
        }
    }

    /**
     * @throws \ReflectionException
     */
    private function findClassInAttributes(array $attributes, $className, $group)
    {
        $classes = [];

        // Split path
        foreach ($attributes as $attribute => $data) {
            if (!$data) {
                $data = [];
            }

            $previousPathElementClass = null;
            $pathElements = explode('.', $attribute);
            $length = count($pathElements);
            foreach ($pathElements as $elementIndex => $pathElement) {

                switch (true) {

                    // Dead ends
                    case \str_starts_with($pathElement, '_security_'):
                    case $pathElement === '_security':
                    case \str_starts_with($pathElement, '_maxDepth'):
                    case \str_starts_with($pathElement, '_serializedName'):
                    case \str_starts_with($pathElement, '_ignore'):
                    case \str_starts_with($pathElement, '_'):
                        break;

                    // process "normal" attribute
                    default:
                        // Get parent class for current attribute
                        if (!$previousPathElementClass) {
                            $previousPathElementClass = $className; // Initial class given in parameters
                        }

                        // Process path element as a class
                        if (!isset($classes[$previousPathElementClass])) {
                            $classes[$previousPathElementClass] = [$group => [$pathElement => []]];
                        } else {
                            $classes[$previousPathElementClass] = \array_merge_recursive($classes[$previousPathElementClass], [$group => [$pathElement => []]]);
                        }

                        $previousPathElementClass = $this->getPathElementClass((new ClassMetadata($previousPathElementClass))->getReflectionClass(), $pathElement);

                        if ($elementIndex === $length - 1 && $previousPathElementClass) {
                            $classes = \array_merge_recursive($classes, $this->findClassInAttributes(
                                $data,
                                $previousPathElementClass,
                                $group
                            ));
                        }
                }

            }
        }

        return $classes;
    }

    /**
     * Returns method return type or property type
     *
     * @param \ReflectionClass $reflectionClass
     * @param string $property
     * @return ?string
     * @throws \ReflectionException
     */
    private function getPathElementClass(\ReflectionClass $reflectionClass, string $property): ?string
    {
        $methodName = 'get' . static::asCamelCase($property);

        if ($reflectionClass->hasMethod($methodName)) {

            $type = $reflectionClass->getMethod($methodName)->getReturnType();
            $type = $type ? $type->getName() : null;
            if (in_array($type, [ArrayCollection::class, Collection::class])) {
                $type = null;
            }

            // Search type in doc comment @return if no return type declared on method
            if (!$type) {
                preg_match('/@return *([a-zA-Z]*)(\[])?/', $reflectionClass->getMethod($methodName)->getDocComment(), $matches);

                if (isset($matches[1])) {
                    $type = $matches[1];
                }
            }

            if (\class_exists($type) && $type !== Collection::class) {
                return $type;
            }

            if (\class_exists("{$reflectionClass->getNamespaceName()}\\{$type}")) {
                return "{$reflectionClass->getNamespaceName()}\{$type}";
            }
        }

        // Check for doctrine relation
        if ($reflectionClass->hasProperty($property)) {

            $annotationReader = new AnnotationReader();
            $annotations = $annotationReader->getPropertyAnnotations(
                new \ReflectionProperty($reflectionClass->getName(), $property)
            );

            foreach ($annotations as $annotation) {
                switch($annotation) {
                    case $annotation instanceof OneToMany:
                    case $annotation instanceof ManyToMany:
                    case $annotation instanceof OneToOne:
                    case $annotation instanceof ManyToOne:
                        $type = $annotation->targetEntity;

                        if (\class_exists($type)) {
                            return $type;
                        }
                }
            }

            // get from attributes
            // retrieve attributes for property
            $attributes = (new \ReflectionProperty($reflectionClass->getName(), $property))->getAttributes();
            foreach ($attributes as $attribute) {
                $attributeClassName = $attribute->getName();
                switch ($attributeClassName) {
                    case OneToMany::class:
                    case ManyToMany::class:
                    case OneToOne::class:
                    case ManyToOne::class:
                        $type = $attribute->newInstance()->targetEntity;

                        if (\class_exists($type)) {
                            return $type;
                        }
                }
            }

            // Look for property type
            $type = $reflectionClass->getProperty($property)->getType();
            $type = $type ? $type->getName() : null;

            // Search type in doc comment @var
            if (!$type) {
                preg_match('/@var *([a-zA-Z]*)(\[])?/', $reflectionClass->getProperty($property)->getDocComment(), $matches);

                if (isset($matches[1])) {
                    $type = $matches[1];
                }
            }

            if (\class_exists($type)) {
                return $type;
            }

            if (\class_exists("{$reflectionClass->getNamespaceName()}\{$type}")) {
                return "{$reflectionClass->getNamespaceName()}\{$type}";
            }
        }

        return null;
    }

    public static function asCamelCase(string $str): string
    {
        return strtr(ucwords(strtr($str, ['_' => ' ', '.' => ' ', '\\' => ' '])), [' ' => '']);
    }
}
