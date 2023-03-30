# Colony

Super lightweight and easy-to-use HTML template engine for PHP that fully supports localization. Direct links:

- [Getting started](#getting-started)
- [Wiki](https://github.com/ljans/colony/wiki)
- [Demo](https://lab.luniverse.de/colony/demo/)


## Motivation

Template engines help us to split the structure and content of (web) applications.
They are great to prevent bad practice in generating HTML documents like it's done the following example. **Please don't do this!**

```php
print '<div class="'.$className.'">';
print '<ul>';
foreach($items as $item) {
	print '<li>'.$item.'</li>';
}
print '</ul>';
```

One approach is to create an HTML template that does not contain PHP code but rather some "placeholders" marking where the real data should be inserted.
They are commonly denoted by curly braces, so a proper template for the above example could look like this:

```html
<div class="{{className}}">
<ul>
	{{#each items}}
		<li>{{this}}</li>
	{{/each}}
</ul>
```

When fed with such a template alongside the raw data, a template engine substitutes all placeholders and outputs an individual HTML document.
That's already much better!
But can we go further?

*Colony* aims to reduce the overhead even more, focusing on the built-in HTML principle of attributes instead of non-generic placeholders.
Here the above template could simply look like this:

```html
<div :class>
<ul>
	<li :foreach="items" :text></li>
</ul>
```

This is as close to plain HTML as it gets, making templates much easier to create and maintain.


## Getting started

Download a copy of *Colony* and include it into your PHP file:

```php
require 'Colony.php';
require 'Colony.Handlers.php';
```

Then create an instance of the `Colony` class:

```php
$colony = new Colony();
```

By default, your templates are expected to be in a folder called `templates`.
Create an HTML file there and render it by using

```php
print $colony->renderTemplateFile('filename.html', $data);
```

where `$data` holds your data, for example:

```php
$data = [
	'object' => [
		'key' => 'value',
		'now' => new DateTime(),
		'numbers' => [1, 3, 9],
	],
];
```

Learn more in the [wiki](https://github.com/ljans/colony/wiki) or check out the [live demo](https://lab.luniverse.de/colony/demo/).
