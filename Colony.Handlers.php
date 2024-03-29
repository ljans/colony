<?php namespace Colony;
/*!
 * Default handlers for Colony v2.4
 * Licensed under the MIT license
 * Copyright (c) 2023 Lukas Jans
 * https://github.com/ljans/colony
 */
abstract class Handler {
	protected $colony;
	static $attribute;
	
	// Construct with colony instance
	public function __construct($colony) {
		$this->colony = $colony;
	}
}

/**
 * The assigned property is used as new local dataset for this node and all of its children.
 *
 * Example 1: <div :="user" :text="name"></div>
 * Equivalent to: <div :text="user/name"></div>
 *
 * Example 2: <div :="one" ::="two" :::="three"></div>
 * Equivalent to: <div :="one/two/three"></div>
 */
class NestingHandler extends Handler {
	static $attribute = '';
	public function process($node, $stacks, $globalData, &$localData, $stack) {
		
		// Use the ordered list of levels to cascade through several layers of a multidimensional array in one element
		foreach($stack as $value) $localData = $this->colony->findProperty($value, $globalData, $localData);
	}
}

/**
 * The element including all its children get repeated for each item in an assigned array
 *
 * Example: <div :foreach="products"></div>
 */
class ForeachHandler extends Handler {
	static $attribute = 'foreach';
	public function process($node, $stacks, $globalData, $localData, $stack) {
		
		// Start with the local data as single item to iterate over
		$totalItems = [$localData];
		
		// Go through the ordered list of levels
		foreach($stack as $level => $value) {
			
			// If an expression (level 0) is provided, use it as JSON source for the array of items
			if($level === 0) {
				$totalItems = json_decode($value, true);
				continue;
			}
			
			// Try to go one level deeper in each of the current items (which are supposed to be arrays themselves if another level is specified) or keep the current level if not
			$newTotalItems = [];
			foreach($totalItems as $item) {
				$array = $this->colony->findProperty($value, $globalData, $item);
				if(is_array($array)) $newTotalItems = array_merge($newTotalItems, $array);
				else $newTotalItems[] = $item;
			}
			$totalItems = $newTotalItems;
		}
		
		// Clone the node for each item, insert it, process it and check whether it should be removed. Must be in this order, see AppendHandler for more information.
		foreach($totalItems as $childElementData) {
			$copy = $node->cloneNode(true);
			$copy = $node->parentNode->insertBefore($copy, $node);
			if($this->colony->processNode($copy, $stacks, $globalData, $childElementData)) $node->parentNode->removeChild($copy);
		}
			
		// Mark the current node for removal
		return true;
	}
}

/**
 * The element including all its children are removed if a condition does not meet the specified mode
 */
abstract class ConditionHandler extends Handler {
	static $mode;
	public function process($node, $stacks, $globalData, $localData, $stack) {
		
		// Each level represents a single condition, logically they are all connected with AND
		foreach($stack as $level => $value) {
			
			// If an expression (level 0) is provided, use it as comparision string
			if($level === 0) {
				$compareTo = $value;
				continue;
			}
			
			// Mark the node for deletion if the assigned data does or does not meet the condition, depending on the speicifed mode
			$data = $this->colony->findProperty($value, $globalData, $localData);
			$conditionMet = isset($compareTo) ? $data == $compareTo : !empty($data);
			if($conditionMet !== $this::$mode) return true;
		}
	}
}

/**
 * Subclass of the ConditionHandler superclass that requires the condition to be met
 *
 * Example: <div :with="login" ::with="admin">Special rights granted</div>
 */
class WithHandler extends ConditionHandler {
	static $attribute = 'with';
	static $mode = true;
}

/**
 * Subclass of the ConditionHandler superclass that requires the condition not to be met
 *
 * Example: <div :without="login">Not logged in</div>
 */
class WithoutHandler extends ConditionHandler {
	static $attribute = 'without';
	static $mode = false;
}

/**
 * Process the value like for a regular attribute but set it as text instead
 *
 * Example: <div :text="age">I am %d years old</div>
 */
class TextHandler extends Handler {
	static $attribute = 'text';
	public function process($node, $stacks, $globalData, $localData, $stack) {
		$value = $this->colony->processValue($stack, $this::$attribute, $globalData, $localData);
		
		// Converting HTML special chars is required because e.g. & would otherwise yield "unterminated entity reference"
		if($node->childNodes->length === 0 || isset($stack[0])) $node->nodeValue = htmlspecialchars($value ?? '');
	}
}

/**
 * Process the value like for a regular attribute but set is as html instead
 *
 * Example: <div :html="article/text"></div>
 */
class HTMLHandler extends Handler {
	static $attribute = 'html';
	public function process($node, $stacks, $globalData, $localData, $stack) {
		$value = $this->colony->processValue($stack, $this::$attribute, $globalData, $localData);
		if($value == '') return;
		$extraDOM = $this->colony->loadHTML($value);
		$this->colony->append($extraDOM, $node, $globalData, $localData);
	}
}

/**
 * Process the value like for a regular attribute but use it as filename for a template to append
 *
 * Example: <div append="extra.html"></div>
 */
class AppendHandler extends Handler {
	static $attribute = 'append';
	public function process($node, $stacks, $globalData, $localData, $stack) {
		$href = $this->colony->processValue($stack, $this::$attribute, $globalData, $localData);
		$template = $this->colony->getTemplate($href);
		if($template == '') return;
		$extraDOM = $this->colony->loadHTML($template);
		$this->colony->append($extraDOM, $node, $globalData, $localData);
	}
}
