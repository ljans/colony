<?php
/*!
 * Colony HTML template engine v1.1
 * Licensed under the MIT license
 * Copyright (c) 2022-05 Lukas Jans
 * https://github.com/ljans/colony
 */
class Colony {
	
	// Constants for labelling the attributes of a feature (must be string for array_merge to work)
	const EXPRESSION = 'expression';
	const EARLY = 'earlyAssignmentSelectorPrefix';
	const LATE = 'lateAssignmentSelectorPrefix';

	// Array for expressions from ini file
	private $expressions = [];
	
	// Default configuration
	private $defaultConfig = [
		self::LATE => ':',
		self::EARLY => '.',
		'textSelector' => 'text',
		'nestingDelimiter' => '/',
		'templateFolder' => 'templates',
	];
	
	// Construct with configuration array
	private $config;
	public function __construct($config=[]) {
		$this->config = array_merge($this->defaultConfig, $config);
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
	private function findProperty($selector, $globalData, $localData) {
		
		// Return the local data if the selector is empty (attribute had value)
		if($selector === '') return $localData;
		
		// Split the selector in segments by the delimiter
		$segments = explode($this->config['nestingDelimiter'], $selector);
		
		// Decide, whether the selector is local or global (=starts with delimiter)
		if($segments[0] === '') {
			unset($segments[0]);
			$data = $globalData;
		} else $data = $localData;
			
		// Cascade through the data array
		foreach($segments as $name) {
			if(!is_array($data)) return;
			if($name !== '') $data = $data[$name]; // In case the selector ends with the delimiter (or is nothing more than that)
			if(!isset($data)) return;
		}
		return $data;
	}
	
	// Extract the named attribute from the node (removes & returns it, returns NULL if the attribute does not exist)
	private function extractAttribute($node, $name) {
		if(!$node->hasAttribute($name)) return;
		$value = $node->getAttribute($name);
		$node->removeAttribute($name);
		return $value;
	}
	
	// Get all features of the node and remove them afterwards
	private function extractFeatures($node) {
		$features = [];
		$deleteAttributes = [];
		foreach($node->attributes as $attribute) {
			if($this->config[self::LATE] !== $this->config[self::LATE]) var_Dump($this->config[self::LATE]);
			
			// Identify feature parts by their (possible) attribute name prefix
			foreach([
				self::EARLY => $this->config[self::EARLY], // Must be checked first in case it starts with the other prefix, like '::' and ':'
				self::LATE => $this->config[self::LATE],
				self::EXPRESSION => '', // Must be checked last, because it is the prefix of every string
			] as $type => $check) {
				if(substr($attribute->nodeName, 0, strlen($check)) !== $check) continue;
				
				// Group attributes by their qualified name (= without prefix) to form a feature
				$qualifiedName = substr($attribute->nodeName, strlen($check));
				$features[$qualifiedName][$type] = $attribute->nodeValue;
				$deleteAttributes[] = $attribute->nodeName;
				break;
			}
		}
		
		// Remove attributes in a seperate loop after that, not breaking the sibling-chain (same as for nodes, see processChildren)
		foreach($deleteAttributes as $name) $node->removeAttribute($name);
		return $features;
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
	 * The order of processing the features matters (illustrated with default config):
	 * ':' should be processed first, so the other features can work with the bound property
	 * ':foreach' should be processed before ':with'/':without', because :foreach on something empty makes the element disappear anyhow.
	 * But this way children can be checked against specific data for each of them.
	 * ':as' should be processed after ':foreach', otherwise the whole array will be bound instead of the array element
	 * ':import' should be processed after the arguments, so the 'href' may be set dynamically
	*/
	private function processNode($node, $globalData, $localData=NULL) {
		
		// Skip irrelevant nodes
		if(!($node instanceof DOMElement)) return; // <!DOCTYPE html> etc.
		if($node instanceof DOMText) return; // Text between actual nodes (mostly whitespace), can't have a selector anyway
		
		// Process special features
		{
			// Check whether the element should be bound to a property
			$selector = $this->extractAttribute($node, $this->config[self::LATE]);
			if(!is_null($selector)) $localData = $this->findProperty($selector, $globalData, $localData);
			
			// Check whether the element should be repeated for each element in an array
			$selector = $this->extractAttribute($node, $this->config[self::LATE].'foreach');
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
			$selector = $this->extractAttribute($node, $this->config[self::LATE].'with');
			if(!is_null($selector) && empty($this->findProperty($selector, $globalData, $localData))) return true;
			
			// Check whether the element should only be rendered, if a property IS empty
			$selector = $this->extractAttribute($node, $this->config[self::LATE].'without');
			if(!is_null($selector) && !empty($this->findProperty($selector, $globalData, $localData))) return true;
			
			// Check whether the local data (e. g. array element) should be made available in the global data
			$as = $this->extractAttribute($node, $this->config[self::LATE].'as');
			if(!is_null($as)) $globalData[$as] = $localData;
		}
		
		// Process remaining features
		{
			// If the node contains only text or nothing (text might be set by :text), treat its content as expression for a text-feature
			$isEmpty = $node->childNodes->length === 0;
			$onlyText = $node->childNodes->length === 1 && $node->firstChild instanceof DOMText;
			if($isEmpty || $onlyText) {
				
				// Assemble the text-feature
				$feature = [
					self::EXPRESSION => $node->nodeValue,
					self::EARLY => $this->extractAttribute($node, $this->config[self::EARLY].$this->config['textSelector']),
					self::LATE => $this->extractAttribute($node, $this->config[self::LATE].$this->config['textSelector']),
				];
				
				// Check, whether the feature should be "activated" (consists of either an expression or at least one selector)
				if($feature[self::EXPRESSION] !== '' || isset($feature[self::EARLY]) || isset($feature[self::LATE])) {
				
					// Process and set the value
					$value = $this->processValue($feature, $this->config['textSelector'], $globalData, $localData);
					$node->nodeValue = $value ?? '';
				}
			}
			
			// Process and set the values of the remaining features
			foreach($this->extractFeatures($node) as $name => $feature) {
				$value = $this->processValue($feature, $name, $globalData, $localData);
				if(isset($value)) $node->setAttribute($name, $value);
			}
		}
		
		// Process special elements
		{
			// Check whether a partial template should be imported here
			if($node->nodeName === $this->config[self::LATE].'import') {
				
				// Load the document by its href (inside the configured template folder)
				$import = $this->getDocument($node->getAttribute('href'));
				
				// The document must only contain one direct child node, otherwise PHP fails parsing the document
				if($import->childNodes->length > 1) throw new Exception('more than one child in import, use surrounding template');
				
				// Multiple elements might be surrounded in a <:template> tag that just prevents PHP issues and won't be rendered
				if($import->firstChild->nodeName === $this->config[self::LATE].'template') $import = $import->firstChild;
				
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
	
	// Brings expressions together with data. Returns NULL if the assignment is invalid to clean expressions like class="%s" without proper assignment
	private function processAssignment($expression, $selector, $defaultSelector, $globalData, $localData) {
		
		// Determine the feature data by a specified selector (like :class="selector" instead of just :class to only activate the feature)
		if($selector !== '') $featureData = $this->findProperty($selector, $globalData, $localData);
	
		// Otherwise use the default selector
		else {
			$featureData = $this->findProperty($defaultSelector, $globalData, $localData);
			
			// In some cases, the local data should be directly applied to a feature.
			// This is viable for scalars, pure arrays (to allow multiple placeholders %s) and objects (typically DateTime)
			if(!isset($featureData)) {
				if(is_scalar($localData) || $this->isArray($localData) || is_object($localData)) $featureData = $localData;
			}
		}
		
		// If there is a valid assignment and expression given, return the evaluation result
		if(isset($featureData) && isset($expression) && $expression !== '') return $this->evaluate($expression, $featureData);
		
		// Otherwise directly return the (appropriate) data as value. For better control of non-valued features like :readonly, false is excluded (removes the feature)
		elseif(is_scalar($featureData) && $featureData !== false) return $featureData;
	}
	
	// Process the value of a feature. Only called, if there is at least one selector or an expression (but may be empty)
	private function processValue($feature, $defaultSelector, $globalData, $localData) {
		
		// Start with a (possibly) given expression (or NULL)
		$value = $feature[self::EXPRESSION];
		
		// Process the possible early assignment
		if(isset($feature[self::EARLY])) $value = $this->processAssignment($value, $feature[self::EARLY], $defaultSelector, $globalData, $localData);
		
		// Substitute a possible expression
		$value = $this->expressions[strtolower($value)] ?? $value;
		
		// Process the possible late assignment
		if(isset($feature[self::LATE])) $value = $this->processAssignment($value, $feature[self::LATE], $defaultSelector, $globalData, $localData);
		
		// Return the actual feature value
		return $value;
	}
	
	// Evaluate expression with data. Returns NULL if the data is not applicable or intentionally set to false. The feature will be removed in this case.
	private function evaluate($expression, $data) {
		if(is_object($data)) return $data->format($expression); // Mostly DateTime
		if(is_array($data)) return vsprintf($expression, $data); // Multiple placeholders
		if(is_scalar($data) && $data !== false) return sprintf($expression, $data); // Single placeholder
	}
	
	// Check whether the provided data is a non-associative array (has only numeric keys)
	private function isArray($data) {
		return is_array($data) && array_values($data) === $data;
	}
}