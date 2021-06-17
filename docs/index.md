```yaml
# config/easy-serializer/test-serializer

App\Entity\A:
    item.normalization.get:
        d:
            _security_admin: is_granted('ROLE_ADMIN') # User must be admin path to get deeper serialization
            _admin.name:
                _maxDepth: 1
        b:
            _maxDepth: 3
            cs:
                _security: object.getName() === 'I am A' # Root serialized object name (App\Entity\A) must be "Ah" to serialize cs and deeper
                name: ~ # or you can let empty
```

If you serialize this entity while connected as admin, you'll obtain the following response : 

```json
{
  "@context": "/contexts/A",
  "@id": "/as/1",
  "@type": "A",
  "d": {
    "@id": "/ds/1",
    "@type": "D",
    "name": "I am D"
  },
  "b": {
    "@id": "/bs/1",
    "@type": "B",
    "cs": [
      {
        "@id": "/cs/1",
        "@type": "C",
        "name": "I am C"
      },
      {
        "@id": "/cs/2",
        "@type": "C",
        "name": "I am C2"
      }
    ]
  }
}
```
