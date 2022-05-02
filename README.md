# Colony: a PHP template processor

Template processors help us, to split the structure and content of applications.
They are great to prevent **bad code** like that:

```html
<div class="<?php print $className; ?>">
<ul>
	<?php foreach($items as $item) print '<li>'.$item.'</li>'; ?>
</ul>
```

Common template processors use placeholders based on curly braces, to transform the above code into the following:

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
Check out the [live demo](https://lab.luniverse.de/colony/demo/) of *Colony* or learn more in the [wiki](https://github.com/ljans/colony/wiki).
