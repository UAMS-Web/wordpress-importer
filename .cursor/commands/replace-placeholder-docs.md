# replace-placeholder-docs

Replace instances of `// ???` with appropriate line-level comment documentation explaining the action.

If the line-level comment contains multiple sentences, use normal sentence punctuation. If the comment does not include multiple sentences, do not end the comment in a period.

If the comment after the `// ` in the line-level comment will exceed 80 characters, split the comment into multiple lines and flank the comment with lines of `// ---`.

e.g.:
```php
// Lorem ipsum dolor sit amet, consectetuer adipiscing elit
```
```php
// Quisque rutrum. Aenean imperdiet.
```
```php
// ---
// Lorem ipsum dolor sit amet, consectetuer adipiscing elit. Aenean commodo ligula
// eget dolor. Aenean massa. Cum sociis natoque penatibus et magnis dis parturient
// montes, nascetur ridiculus mus.
// ---
```

Replace instances of `???` in PHPDoc blocks with appropriate documentation.

Add descriptions to PHPDoc blocks that have no descriptions.

Add PHPDoc blocks where missing for properties and methods.
