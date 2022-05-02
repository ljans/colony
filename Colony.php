<?php
/*!
 * Colony PHP template processor v1.0
 * Licensed under the MIT license
 * Copyright (c) 2022-05 Lukas Jans
 * https://github.com/ljans/colony
 */
class Colony {

	// Array for expressions from ini file
	private $expressions = [];
	
	// Default configuration
	private $defaultConfig = [
		'textAttribute' => 'text',
		'templateFolder' => 'templates',
	];
	
	// Construct with configuration array
	private $config;
	public function __construct($config=[]) {
		$this->config = array_merge($this->defaultConfig, $config);
	}
	
	// Load an expression file
	public function loadExpressionsFile($path) {
		
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
	private function findProperty($selector, $globalData, $localData) {
		
		// Return the local data if the selector is empty (attribute without value)
		if($selector === '') return $localData;
		
		// Split the selector in segments by the delimiter
		$segments = explode('/', $selector);
		
		// Decide, whether the selector is local or global (=starts with delimiter)
		if($segments[0] === '') {
			unset($segments[0]);
			$data = $globalData;
		} else $data = $localData;
			
		// Cascade through the data array
		foreach($segments as $name) {
			if(!is_array($data)) return;
			if($name !== '') $data = $data[$name]; // In case the selector ends with '/' (or is nothing more than that)
			if(!isset($data)) return;
		}
		return $data;
	}
	
	// Extract the named attribute from the node (removes & returns it, returns NULL if attribute does not exist)
	private function extractAttribute($node, $name) {
		if(!$node->hasAttribute($name)) return;
		$value = $node->getAttribute($name);
		$node->removeAttribute($name);
		return $value;
	}
	
	// Get all remaining attributes of the node in the form [selector (attribute with colon), template (attribute without colon)] and remove them
	private function extractRemainingAttributes($node) {
		$attributes = [];
		$deleteAttributes = [];
		foreach($node->attributes as $attribute) {
			$isBinding = $attribute->nodeName[0] === ':';
			//if($isBinding) $this->enableExpressions = true;
			$qualifiedName = substr($attribute->nodeName, $isBinding);
			$attributes[$qualifiedName][$isBinding] = $attribute->nodeValue;
			$deleteAttributes[] = $attribute->nodeName;
		}
		
		// Remove attributes in a seperate loop after that, not breaking the sibling-chain (same as for nodes)
		foreach($deleteAttributes as $name) $node->removeAttribute($name);
		return $attributes;
	}
	
	// Get the DOM of a HTML file in the template folder
	private function getDocument($filename) {
		$document = new DOMDocument();
		$flags = LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD; // LIBXML_NOBLANKS is not really working
		@$document->loadHTMLFile($this->config['templateFolder'].'/'.$filename, $flags);
		return $document;
	}
	
	/** Process all children of the provided node
	 * If processNode evaluates to true, the node should be removed.
	 * The removal must not happen, before all nodes are processed, because the 'foreach' loop internally seems to work with 'nextSibling'.
	 * That means if the current node gets removed, it has no more next sibling and the loop immediately ends.
	 */
	private function processChildren($node, $globalData, $localData) {
		$remove = [];
		foreach($node->childNodes as $child) {
			if($this->processNode($child, $globalData, $localData)) $remove[] = $child;
		}
		foreach($remove as $child) $node->removeChild($child);
	}
	
	/** Process a node. Returns true, if the node should be removed, otherwise NULL
	 * The order of processing the attributes matters:
	 * ':' should be processed first, so the other attributes can work with the bound property
	 * ':foreach' should be processed before ':with'/':without', because :foreach on NULL makes the element disappear anyhow.
	 * But this way children can be checked against specific data for each of them.
	 * ':as' should be processed after ':foreach', otherwise the whole array will be bound instead of the array element
	 * ':import' should be processed after the arguments, so the 'href' may be set dynamically
	*/
	private function processNode($node, $globalData, $localData=NULL) {
				
		// Skip irrelevant nodes
		if(!($node instanceof DOMElement)) return; // <!DOCTYPE html> etc.
		if($node instanceof DOMText) return; // Text between actual nodes (mostly whitespace), can't be bound to a property by attribute ':' anyway
		
		// Process special attributes
		{
			// Check whether the element should be bound to a property
			$selector = $this->extractAttribute($node, ':');
			if(!is_null($selector)) $localData = $this->findProperty($selector, $globalData, $localData);
			
			// Check whether the element should be repeated for each element in an array
			$selector = $this->extractAttribute($node, ':foreach');
			if(!is_null($selector)) {
				$array = $this->findProperty($selector, $globalData, $localData);
							
				// Iterate over each element of the (possible) array
				if(is_array($array)) foreach($array as $childElementData) {
									
					// Clone the node, insert it, process it and check whether it should be removed. Must be in this order, see :import
					$copy = $node->cloneNode(true);
					$node->parentNode->insertBefore($copy, $node);
					if($this->processNode($copy, $globalData, $childElementData)) $node->parentNode->removeChild($copy);
				}
				
				// Mark the current node for removal
				return true;
			}
			
			// Check whether the element should only be rendered, if a property is NOT empty
			$selector = $this->extractAttribute($node, ':with');
			if(!is_null($selector) && empty($this->findProperty($selector, $globalData, $localData))) return true;
			
			// Check whether the element should only be rendered, if a property IS empty
			$selector = $this->extractAttribute($node, ':without');
			if(!is_null($selector) && !empty($this->findProperty($selector, $globalData, $localData))) return true;
			
			// Check whether the local data (e. g. array element) should be made available in the global data
			$as = $this->extractAttribute($node, ':as');
			if(!is_null($as)) $globalData[$as] = $localData;
		}
		
		// Process regular attributes and text content
		{
			// If the node contains only text or nothing (text might be set by :text), treat its content as attribute
			$isEmpty = $node->childNodes->length === 0;
			$onlyText = $node->childNodes->length === 1 && $node->firstChild instanceof DOMText;
			if($isEmpty || $onlyText) {
				
				// Determine template and selector for the text
				$template = $node->nodeValue;
				$selector = $this->extractAttribute($node, ':'.$this->config['textAttribute']);
				
				// Check, whether the attribute should be "activated" (has either a template with possible expression or a data binding)
				if($template !== '' || isset($selector)) {
				
					// Process and set the value
					$value = $this->processValue($template, $selector, $this->config['textAttribute'], $globalData, $localData);
					if(isset($value)) $node->nodeValue = $value;
				}
			}
			
			// Process and set the values of the remaining attributes
			foreach($this->extractRemainingAttributes($node) as $name => [$template, $selector]) {
				$value = $this->processValue($template, $selector, $name, $globalData, $localData);
				if(isset($value)) $node->setAttribute($name, $value);
			}
		}
		
		// Process special tags
		{
			// Check whether another template should be imported here
			if($node->nodeName === ':import') {
				
				// Load the document by its href (inside the configured template folder)
				$import = $this->getDocument($node->getAttribute('href'));
				
				// The document must only contain one direct child node, otherwise PHP fails parsing the document
				if($import->childNodes->length > 1) throw new Exception('more than one child in import, use surrounding <:template>');
				
				// Multiple tags might be surrounded in a <:template> tag that just prevents PHP issues and won't be rendered
				if($import->firstChild->nodeName === ':template') $import = $import->firstChild;
				
				/** Import all child nodes, process them and check whether they should be removed
				* Has to be done in this order! Assume there is an element with :foreach in the document that should be imported.
				* On processing the :foreach, copies will be inserted BEFORE, so they won't be imported anymore as this loop already passed them.
				* Then, the raw :foreach element (which has no relevance) will be imported before it gets deleted on the imported document.
				* It won't be processed anymore here. So processing must happen before importing to make things work out.
				*/
				foreach($import->childNodes as $child) {
					$child = $node->ownerDocument->importNode($child, true);
					$node->parentNode->insertBefore($child, $node);
					if($this->processNode($child, $globalData, $localData)) $node->parentNode->removeChild($child);
				}
				
				// Mark the node for deletion
				return true;
			}
		}
		
		// Process all child nodes
		$this->processChildren($node, $globalData, $localData);
	}
	
	// Process the value of an attribute or text content. Only called, if either the selector or template is set (may be empty)
	private function processValue($template, $selector, $defaultSelector, $globalData, $localData) {
		
		// Use the default selector if none is provided
		if(is_null($selector) || $selector === '') {
			$attributeData = $this->findProperty($defaultSelector, $globalData, $localData);
			
			// In some cases, the local data should be directly applied to an attribute.
			// This is viable for strings, pure arrays (to allow multiple placeholders %s) and objects (typically DateTime)
			if(!isset($attributeData)) {
				if(is_string($localData) || $this->isArray($localData) || is_object($localData)) $attributeData = $localData;
			}
			
		// Otherwise, find the selected property
		} else $attributeData = $this->findProperty($selector, $globalData, $localData);
		
		// If the template is not empty (attribute <name>="..." instead of just <name>), substitute possible expressions first
		if(isset($template) && $template !== '') {
			$displayedValue = $this->expressions[strtolower($template)] ?? $template;
			
			// If there is a data binding, format the template with it
			if(isset($attributeData)) $displayedValue = $this->format($attributeData, $displayedValue);
		
		// If the template is not provided or empty (to just trigger this function if no selector is provided),
		// scalar data is directly used as displayed value, as long as it's not false. This is useful for switches like ':readonly'
		} elseif(is_scalar($attributeData) && $attributeData !== false) $displayedValue = $attributeData;
		
		// Return the actual attribute/textnode value. Is null if none of the above cases apply
		return $displayedValue;
	}
	
	// Format data. Returns NULL if the data is not applicable. The attribute or text content won't be set then
	private function format($data, $format) {
		if(is_object($data)) return $data->format($format); // Mostly DateTime
		if(is_array($data)) return sprintf($format, ...$data); // Multiple placeholders
		if(is_scalar($data)) return sprintf($format, $data); // Single placeholder
	}
	
	// Check whether the provided data is a non-associative array (has only numeric keys)
	private function isArray($data) {
		return is_array($data) && array_values($data) === $data;
	}
}