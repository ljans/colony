# Colony: a PHP template engine

Template engines help us, to split the structure and content of applications.
They are great to prevent **bad code** like that:

```html
<div class="<?php print $className; ?>">
<ul>
	<?php foreach($items as $item) print '<li>'.$item.'</li>'; ?>
</ul>
```

Common template engines use placeholders based on curly braces, to transform the above code into the following:

```html
<div class="{{className}}">
<ul>
	{{#each items}}
		<li>{{this}}</li>
	{{/each}}
</ul>
```

That's already better!
But can we go further?

*Colony* aims to reduce the overhead even more, focusing on the built-in HTML principle of attributes.
Here the above code would look like this:

```html
<div :class>
<ul>
	<li :foreach="items" :text></li>
</ul>
```

This is as close to plain HTML as it gets.


## Getting started

Download a copy of [`Colony.php`](Colony.php) and include it into your PHP file:

```php
require 'Colony.php';
```

Next, create and instance of the `Colony` class:

```php
$colony = new Colony();
```

By default, _Colony_ expects your templates to be in a folder called `templates`.
Put a template there and render it by using:

```php
print $colony->renderTemplateFile('filename.html', [
	'object' => [
		'key' => 'value',
		'now' => new DateTime(),
		'numbers' => [1, 3, 9],
	]
]);
```

Expression files can be loaded before via:

```php
$colony->loadExpressionsFile('path/to/filename.ini');
```

Check out the [live demo](https://lab.luniverse.de/colony/demo/) of *Colony* or learn more in the [wiki](https://github.com/ljans/colony/wiki).
