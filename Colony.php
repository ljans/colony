<?php
/*!
 * Colony HTML template engine v2.0
 * Licensed under the MIT license
 * Copyright (c) 2023 Lukas Jans
 * https://github.com/ljans/colony
 */
class Colony {

	// Array for expressions from ini file
	private $expressions = [];
	
	// Array for attribute handlers
	private $handlers = [];
	
	// Default configuration
	private $defaultConfig = [
		'nestingSeparator' => '/',
		'listingSeparator' => ',',
		'templateFolder' => 'templates',
	];
	
	// Default handlers (ordered)
	private $defaultHandlers = [
		'Colony\NestingHandler', // First, because it's changing the local data for all other handlers
		'Colony\ForeachHandler', // Second, because it's also changing the local data (to the array item of each copy)
		'Colony\WithHandler', 'Colony\WithoutHandler', // Third, because the following handlers may become needless if the element will be removed anyway
		'Colony\TextHandler',
		'Colony\AppendHandler',
	];
	
	// Construct with optional custom configuration array and custom handlers
	private $config;
	public function __construct($config=[], $handlers=NULL) {
		
		// Overwrite the default config with the custom config
		$this->config = array_merge($this->defaultConfig, $config);
		
		// Insantiate all default or (if provided) custom handlers
		foreach($handlers ?? $this->defaultHandlers as $handler) $this->handlers[] = new $handler($this);
	}
	
	// Load an expression file
	public function loadExpressionFile($path) {
		
		// Array of key-value pairs or sections of those or a mix
		$entriesOrSections = parse_ini_file($path, true);
		
		// Iterate over first layer
		foreach($entriesOrSections as $keyOrSectionName => $valueOrEntries) {
			
			// Store plain key-values pairs
			if(is_scalar($valueOrEntries)) {
				$this->expressions[strtolower($keyOrSectionName)] = $valueOrEntries;
				
			// Store all key-value pairs in a group
			} else foreach($valueOrEntries as $key => $value) {
				$this->expressions[strtolower($keyOrSectionName.'.'.$key)] = $value;
			}
		}
	}
	
	// Render a template file with the given data as (associative) array
	public function renderTemplateFile($name, $data=[]) {
		
		// Load the document, process all its direct children (cascades) and return the result HTML
		$document = $this->getDocument($name);
		$this->processChildren($document, $data, $data);
		return $document->saveHTML();
	}
	
	// Find a property in a nested data-object by a selector
	public function findProperty($selector, $globalData, $localData) {
		
		// Return the local data if the selector is empty (attribute level was present but had value '')
		if($selector === '') return $localData;
		
		// Resolve combined selectors
		$parts = explode($this->config['listingSeparator'], $selector);
		if(count($parts) > 1) return array_map(fn($part) => $this->findProperty($part, $globalData, $localData), $parts);
		
		// Split a nested selector in segments
		$segments = explode($this->config['nestingSeparator'], $selector);
		
		// Decide, whether the selector is local or global (starts with separator)
		if($segments[0] === '') {
			unset($segments[0]);
			$data = $globalData;
		} else $data = $localData;
			
		// Cascade through the data array
		foreach($segments as $name) {
			if(!is_array($data)) return;
			if($name !== '') $data = $data[$name] ?? NULL; // In case the selector ends with the separator (or is nothing more than that)
			if(!isset($data)) return;
		}
		return $data;
	}
	
	// Get all attribute stacks of the node and remove them afterwards
	public function extractAttributeStacks($node) {
		$stacks = [];
		$remove = [];
		foreach($node->attributes ?? [] as $attribute) {
			
			// Split into stack level and normalized attribute name
			$name = $attribute->nodeName;
			$level = 0;
			while(strlen($name) > 0 && $name[0] === ':') {
				$level++;
				$name = substr($name, 1);
			}
			
			// Group into stacks
			if(!isset($stacks[$name])) $stacks[$name] = [];
			$stacks[$name][$level] = $attribute->nodeValue;
			$remove[] = $attribute->nodeName;
		}
		
		// Remove attributes in a seperate loop after that, not breaking the sibling-chain (same as for nodes, see processChildren)
		foreach($remove as $name) $node->removeAttribute($name);
		
		// If the node contains only text, use it as level 0 (expression) of the 'text' attribute stack
		if($node->childNodes->length === 1 && $node->firstChild instanceof DOMText) {
			$text = Colony\TextHandler::$attribute;
			if(!isset($stacks[$text])) $stacks[$text] = [];
			$stacks[$text][0] = $node->nodeValue;
		}
		return $stacks;
	}
	
	// Get the DOM of a HTML file in the template folder
	public function getDocument($filename) {
		$document = new DOMDocument();
		$flags = LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD; // LIBXML_NOBLANKS is not really working
		@$document->loadHTMLFile($this->config['templateFolder'].'/'.$filename, $flags); // Surpress errors for invalid html
		return $document;
	}
	
	/** Process all children of the provided node
	 * If processNode evaluates to true, the node will be removed.
	 * The removal must not happen before all nodes are processed, because the 'foreach' loop internally seems to work with 'nextSibling'.
	 * That means if the current node gets removed, it has no more next sibling and the loop immediately ends.
	 */
	public function processChildren($node, $globalData, $localData) {
		$remove = [];
		foreach($node->childNodes as $child) {
			$stacks = $this->extractAttributeStacks($child);
			if($this->processNode($child, $stacks, $globalData, $localData)) $remove[] = $child;
		}
		foreach($remove as $child) $node->removeChild($child);
	}
	
	// Process a node and return true, if it should be removed
	public function processNode($node, $stacks, $globalData, $localData=NULL) {
		
		// Skip irrelevant nodes
		if(!($node instanceof DOMElement)) return; // <!DOCTYPE html> etc.
		if($node instanceof DOMText) return; // Text between actual nodes (mostly whitespace), can't have attributes anyway
		
		// Process attribute handlers if there is a matching stack
		foreach($this->handlers as $handler) {
			if(!isset($stacks[$handler::$attribute])) continue;
			$stack = $stacks[$handler::$attribute];
			unset($stacks[$handler::$attribute]);
			if($handler->process($node, $stacks, $globalData, $localData, $stack) === true) return true;
		}
		
		// Process and set the values of the remaining attribute stacks
		foreach($stacks as $name => $stack) {
			$value = $this->processValue($stack, $name, $globalData, $localData);
			if(isset($value)) $node->setAttribute($name, $value);
		}
		
		// Process all child nodes
		$this->processChildren($node, $globalData, $localData);
		
		// Remove a technical wrapper node (<:></:>) but not its children
		if($node->nodeName === ':') {
			$add = [];
			foreach($node->childNodes as $child) $add[] = $child;
			foreach($add as $child) $node->parentNode->insertBefore($child, $node);
			return true;
		}
	}
	
	// Assign data by it specified selector to an attribute and return NULL if the assignment is invalid to clean expressions like class="%s" without proper assignment
	public function processAssignment($currentValue, $selector, $defaultSelector, $globalData, $localData) {
		
		// Determine the assigned data by a specified selector like :class="selector"
		if($selector !== '') $assignedData = $this->findProperty($selector, $globalData, $localData);
	
		// Otherwise use the default selector (node name) for plain attributes like :class
		else {
			$assignedData = $this->findProperty($defaultSelector, $globalData, $localData);
			
			// If no property could be found, assign the local data itself as long as its a scalar, numeric array (to allow multiple placeholders like %s) or object (typically DateTime)
			if(!isset($assignedData)) {
				if(is_scalar($localData) || $this->isArray($localData) || is_object($localData)) $assignedData = $localData;
			}
		}
		
		// If there is a valid assignment and expression given, return the evaluation result
		if(isset($assignedData) && isset($currentValue) && $currentValue !== '') return $this->evaluate($currentValue, $assignedData);
		
		// Otherwise directly return the (appropriate) data as value. For better control of non-valued attributes like 'readonly', false is excluded and therefore removes the attribute
		elseif(is_scalar($assignedData) && $assignedData !== false) return $assignedData;
	}
	
	// Process the value of an attribute stack if there is at least one level specified (may be empty though)
	public function processValue($stack, $defaultSelector, $globalData, $localData) {
		
		/** Process all attribute stack levels in ascending order
		 * The specific order of the statements below results in the desired process order:
		 * - Use attribute level 0 (the expression) as value (specified by 'example')
		 * - Try to substitute the expression
		 * - Try to process the assignment from attribute level 1 (specified by ':example')
		 * - Try to substitute the expression
		 * - Try to process the assignment from attribute level 2 (specified by '::example')
		 * - ... (as long as there are more levels)
		 * - Try to substitute the expression
		 */
		ksort($stack);
		foreach($stack as $level => $item) {
			
			// If an expression is provided, use it as value
			if($level === 0) $value = $stack[0];
			
			// If a selector is provided, try to process the assignment
			if($level > 0) $value = $this->processAssignment($value ?? NULL, $item, $defaultSelector, $globalData, $localData);
			
			// Try to substitute an expression
			if(isset($value)) $value = $this->expressions[strtolower($value)] ?? $value;
		}
		
		// Return the actual attribute value
		return $value;
	}
	
	// Evaluate an expression with data or return NULL if the data is not applicable or intentionally set to false, which results in the removal of the attribute
	public function evaluate($expression, $data) {
		if(is_object($data)) return $data->format($expression); // Mostly DateTime
		if(is_array($data)) return vsprintf($expression, $data); // Multiple placeholders
		if(is_scalar($data) && $data !== false) return sprintf($expression, $data); // Single placeholder
	}
	
	// Check whether the provided data is a non-associative array (has only numeric keys)
	public function isArray($data) {
		return is_array($data) && array_values($data) === $data;
	}
}