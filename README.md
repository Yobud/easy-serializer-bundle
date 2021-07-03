EasySerializerBundle
====================

EasySerializerBundle aims to make serialization in ApiPlatform easier and smarter with security expressions.

**!!! It's NOT ready for production for now !!!**

It allows you to store your serialization in yaml files like below :

Let's have entities `A`, `B`, `C`, `D` with some relations between them (`a.b`, `a.d`, `b.cd`) with dumb names "Ah", "Beh", "Ceh", "Ceh 2" and "Deh".

```yaml
# api/config/easy-serializer/demo-serializer.yaml

App\Entity\A:
    item.normalization.get: # [item|collection|any].[normalization|denormalization|any].route_name
        name: # will always serialize
        b: # automatically cascade serialization in related entities
            _maxDepth: 3 # supports all ApiPlatform serialization options when prefixed with underscores
            cs:
                _security: object.getName() === 'Ah' # Root serialized object name (App\Entity\A) must be "Ah" to serialize cs and deeper
                name:
        d:
            _security_admin: is_granted('ROLE_ADMIN') # User must be admin path to get deeper serialization / security can be namespaced (here _admin)
            _admin.name:
                _maxDepth: 1

```

Requesting `/as/1` as admin will result in the following :
```json
{
  "@context": "/contexts/A",
  "@id": "/as/1",
  "@type": "A",
  "name": "Ah",
  "d": {
    "@id": "/ds/1",
    "@type": "D",
    "name": "Deh"
  },
  "b": {
    "@id": "/bs/1",
    "@type": "B",
    "cs": [
      {
        "@id": "/cs/1",
        "@type": "C",
        "name": "Ceh"
      },
      {
        "@id": "/cs/2",
        "@type": "C",
        "name": "Ceh 2"
      }
    ]
  }
}
```

Installation
============

Make sure Composer is installed globally, as explained in the
[installation chapter](https://getcomposer.org/doc/00-intro.md)
of the Composer documentation.

Applications that use Symfony Flex
----------------------------------

Open a command console, enter your project directory and execute:

```console
$ composer require yobud/easy-serializer-bundle
```

Applications that don't use Symfony Flex
----------------------------------------

### Step 1: Download the Bundle

Open a command console, enter your project directory and execute the
following command to download the latest stable version of this bundle:

```console
$ composer require yobud/easy-serializer-bundle
```

### Step 2: Enable the Bundle

Then, enable the bundle by adding it to the list of registered bundles
in the `config/bundles.php` file of your project:

```php
// config/bundles.php

return [
    // ...
   Yobud\Bundle\EasySerializerBundle\EasySerializerBundle::class => ['all' => true],
];
