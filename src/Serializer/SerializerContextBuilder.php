<?php

declare(strict_types=1);

namespace Yobud\Bundle\EasySerializerBundle\Serializer;

use ApiPlatform\Core\Api\OperationType;
use ApiPlatform\Core\Exception\RuntimeException;
use ApiPlatform\Core\Metadata\Resource\Factory\ResourceMetadataFactoryInterface;
use Symfony\Component\Security\Core\Authorization\ExpressionLanguage;
use ApiPlatform\Core\Serializer\SerializerContextBuilderInterface;
use ApiPlatform\Core\Swagger\Serializer\DocumentationNormalizer;
use ApiPlatform\Core\Util\RequestAttributesExtractor;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Serializer\Encoder\CsvEncoder;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactoryInterface;

/**
 * {@inheritdoc}
 *
 * @author Jérémy Hubert <jerermyh.contact@gmail.com>
 */
final class SerializerContextBuilder implements SerializerContextBuilderInterface
{
    private $resourceMetadataFactory;
    private $classMetadataFactory;
    private $expressionLanguage;
    private $authorizationChecker;

    /**
     * SerializerContextBuilder constructor.
     * @param ResourceMetadataFactoryInterface $resourceMetadataFactory
     * @param ClassMetadataFactoryInterface $classMetadataFactory
     * @param ExpressionLanguage $expressionLanguage
     * @param AuthorizationCheckerInterface $authorizationChecker
     */
    public function __construct(ResourceMetadataFactoryInterface $resourceMetadataFactory, ClassMetadataFactoryInterface $classMetadataFactory, ExpressionLanguage $expressionLanguage, AuthorizationCheckerInterface $authorizationChecker)
    {
        $this->resourceMetadataFactory = $resourceMetadataFactory;
        $this->classMetadataFactory = $classMetadataFactory;
        $this->expressionLanguage = $expressionLanguage;
        $this->authorizationChecker = $authorizationChecker;
    }

    /**
     * {@inheritdoc}
     */
    public function createFromRequest(Request $request, bool $normalization, array $attributes = null): array
    {
        if (null === $attributes && !$attributes = RequestAttributesExtractor::extractAttributes($request)) {
            throw new RuntimeException('Request attributes are not valid.');
        }

        $resourceMetadata = $this->resourceMetadataFactory->create($attributes['resource_class']);
        $key = $normalization ? 'normalization_context' : 'denormalization_context';
        if (isset($attributes['collection_operation_name'])) {
            $operationKey = 'collection_operation_name';
            $operationType = OperationType::COLLECTION;
        } elseif (isset($attributes['item_operation_name'])) {
            $operationKey = 'item_operation_name';
            $operationType = OperationType::ITEM;
        } else {
            $operationKey = 'subresource_operation_name';
            $operationType = OperationType::SUBRESOURCE;
        }

        $context = $resourceMetadata->getTypedOperationAttribute($operationType, $attributes[$operationKey], $key, [], true);
        $context['operation_type'] = $operationType;
        $context[$operationKey] = $attributes[$operationKey];

        if (!$normalization) {
            if (!isset($context['api_allow_update'])) {
                $context['api_allow_update'] = \in_array($method = $request->getMethod(), ['PUT', 'PATCH'], true);

                if ($context['api_allow_update'] && 'PATCH' === $method) {
                    $context['deep_object_to_populate'] = $context['deep_object_to_populate'] ?? true;
                }
            }

            if ('csv' === $request->getContentType()) {
                $context[CsvEncoder::AS_COLLECTION_KEY] = false;
            }
        }

        $context['resource_class'] = $attributes['resource_class'];
        $context['iri_only'] = $resourceMetadata->getAttribute('normalization_context')['iri_only'] ?? false;
        $context['input'] = $resourceMetadata->getTypedOperationAttribute($operationType, $attributes[$operationKey], 'input', null, true);
        $context['output'] = $resourceMetadata->getTypedOperationAttribute($operationType, $attributes[$operationKey], 'output', null, true);
        $context['request_uri'] = $request->getRequestUri();
        $context['uri'] = $request->getUri();

        if (isset($attributes['subresource_context'])) {
            $context['subresource_identifiers'] = [];

            foreach ($attributes['subresource_context']['identifiers'] as $parameterName => [$resourceClass]) {
                if (!isset($context['subresource_resources'][$resourceClass])) {
                    $context['subresource_resources'][$resourceClass] = [];
                }

                $context['subresource_identifiers'][$parameterName] = $context['subresource_resources'][$resourceClass][$parameterName] = $request->attributes->get($parameterName);
            }
        }

        if (isset($attributes['subresource_property'])) {
            $context['subresource_property'] = $attributes['subresource_property'];
            $context['subresource_resource_class'] = $attributes['subresource_resource_class'] ?? null;
        }

        unset($context[DocumentationNormalizer::SWAGGER_DEFINITION_NAME]);


        if (empty($context['groups'])) {

            $classMetadata = $this->classMetadataFactory->getMetadataFor($attributes['resource_class']);
            foreach ($classMetadata->getAttributesMetadata() as $attributeMetadata) {
                if ('_allGroups' !== $attributeMetadata->getName()) {
                    continue;
                }

                $groups = $attributeMetadata->getGroups();
            }

            $data = null;
            if ($request->attributes->get('data')) {
                $data = $request->attributes->get('data');
            }

            $expressionLanguage = $this->expressionLanguage;
            $variables = [
                'object' => $data,
                'auth_checker' => $this->authorizationChecker, // needed for the is_granted expression function
            ];

            // Filter groups to get only applicable ones
            $context['groups'] = array_filter($groups, function ($group) use ($resourceMetadata, $expressionLanguage, $data, $variables, $operationType, $normalization) {
                $parts = explode(':', $group);

                $concerns = $parts[0];
                $mode = $parts[1];
                $criteria = isset($parts[2]) ? $parts[2] : null;

                switch (true) {
                    case $concerns !== $operationType && 'any' !== $concerns:
                    case $normalization && 'denormalization' !== $mode && 'any' !== $mode:
                        return false;
                    case !$criteria:
                        return true;
                    case \str_contains($criteria, 'object.') && !$data:
                        return false;
                }

                return (bool) $expressionLanguage->evaluate($criteria, $variables);
            });
        }

        if (isset($context['skip_null_values'])) {
            return $context;
        }

        // TODO: We should always use `skip_null_values` but changing this would be a BC break, for now use it only when `merge-patch+json` is activated on a Resource
        foreach ($resourceMetadata->getItemOperations() as $operation) {
            if ('PATCH' === ($operation['method'] ?? '') && \in_array('application/merge-patch+json', $operation['input_formats']['json'] ?? [], true)) {
                $context['skip_null_values'] = true;

                break;
            }
        }

        $context['enable_max_depth'] = true;
        return $context;
    }
}
