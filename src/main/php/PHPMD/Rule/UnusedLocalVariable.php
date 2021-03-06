<?php
/**
 * This file is part of PHP Mess Detector.
 *
 * Copyright (c) Manuel Pichler <mapi@phpmd.org>.
 * All rights reserved.
 *
 * Licensed under BSD License
 * For full copyright and license information, please see the LICENSE file.
 * Redistributions of files must retain the above copyright notice.
 *
 * @author Manuel Pichler <mapi@phpmd.org>
 * @copyright Manuel Pichler. All rights reserved.
 * @license https://opensource.org/licenses/bsd-license.php BSD License
 * @link http://phpmd.org/
 */

namespace PHPMD\Rule;

use PHPMD\AbstractNode;
use PHPMD\Node\AbstractCallableNode;
use PHPMD\Node\ASTNode;

/**
 * This rule collects all local variables within a given function or method
 * that are not used by any code in the analyzed source artifact.
 */
class UnusedLocalVariable extends AbstractLocalVariable implements FunctionAware, MethodAware
{
    /**
     * Found variable images within a single method or function.
     *
     * @var array(string)
     */
    private $images = array();

    /**
     * This method checks that all local variables within the given function or
     * method are used at least one time.
     *
     * @param \PHPMD\AbstractNode $node
     * @return void
     */
    public function apply(AbstractNode $node)
    {
        $this->images = array();

        /** @var $node AbstractCallableNode */
        $this->collectVariables($node);
        $this->removeParameters($node);

        foreach ($this->images as $nodes) {
            if (!$this->containsUsages($nodes)) {
                $this->doCheckNodeImage($nodes[0]);
            }
        }
    }

    /**
     * Return true if one of the passed nodes contains variables usages.
     *
     * @param array $nodes
     *
     * @return bool
     */
    private function containsUsages(array $nodes)
    {
        if (count($nodes) === 1) {
            return false;
        }

        foreach ($nodes as $node) {
            $parent = $node->getParent();

            if (!$parent->isInstanceOf('AssignmentExpression')) {
                return true;
            }

            if (in_array($this->getNode($node), array_slice($parent->getChildren(), 1))) {
                return true;
            }
        }

        return false;
    }

    /**
     * This method removes all variables from the <b>$_images</b> property that
     * are also found in the formal parameters of the given method or/and
     * function node.
     *
     * @param \PHPMD\Node\AbstractCallableNode $node
     * @return void
     */
    private function removeParameters(AbstractCallableNode $node)
    {
        // Get formal parameter container
        $parameters = $node->getFirstChildOfType('FormalParameters');

        // Now get all declarators in the formal parameters container
        $declarators = $parameters->findChildrenOfType('VariableDeclarator');

        foreach ($declarators as $declarator) {
            unset($this->images[$declarator->getImage()]);
        }
    }

    /**
     * This method collects all local variable instances from the given
     * method/function node and stores their image in the <b>$_images</b>
     * property.
     *
     *
     * @param \PHPMD\Node\AbstractCallableNode $node
     * @return void
     */
    private function collectVariables(AbstractCallableNode $node)
    {
        foreach ($node->findChildrenOfType('Variable') as $variable) {
            /** @var $variable ASTNode */
            if ($this->isLocal($variable)) {
                $this->collectVariable($variable);
            }
        }

        foreach ($node->findChildrenOfType('CompoundVariable') as $variable) {
            $this->collectCompoundVariableInString($variable);
        }

        foreach ($node->findChildrenOfType('VariableDeclarator') as $variable) {
            $this->collectVariable($variable);
        }
        foreach ($node->findChildrenOfType('FunctionPostfix') as $func) {
            if ($this->isFunctionNameEndingWith($func, 'compact')) {
                foreach ($func->findChildrenOfType('Literal') as $literal) {
                    /** @var $literal ASTNode */
                    $this->collectLiteral($literal);
                }
            }
        }
    }

    /**
     * Stores the given compound variable node in an internal list of found variables.
     *
     * @param \PHPMD\Node\ASTNode $node
     * @return void
     */
    private function collectCompoundVariableInString(ASTNode $node)
    {
        $parentNode = $node->getParent()->getNode();
        $candidateParentNodes = $node->getParentsOfType('PDepend\Source\AST\ASTString');

        if (in_array($parentNode, $candidateParentNodes)) {
            $variablePrefix = $node->getImage();

            foreach ($node->findChildrenOfType('Expression') as $child) {
                $variableName = $child->getImage();
                $variableImage = $variablePrefix . $variableName;

                $this->storeImage($variableImage, $node);
            }
        }
    }

    /**
     * Stores the given variable node in an internal list of found variables.
     *
     * @param \PHPMD\Node\ASTNode $node
     * @return void
     */
    private function collectVariable(ASTNode $node)
    {
        $imageName = $node->getImage();
        $this->storeImage($imageName, $node);
    }

    /**
     * Safely add node to $this->images.
     *
     * @param string $imageName the name to store the node as
     * @param \PHPMD\Node\ASTNode $node the node being stored
     * @return void
     */
    private function storeImage($imageName, ASTNode $node)
    {
        if (!isset($this->images[$imageName])) {
            $this->images[$imageName] = array();
        }

        $this->images[$imageName][] = $node;
    }

    /**
     * Stores the given literal node in an internal list of found variables.
     *
     * @param \PHPMD\Node\ASTNode $node
     * @return void
     */
    private function collectLiteral(ASTNode $node)
    {
        $variable = '$' . trim($node->getImage(), '\'');

        if (!isset($this->images[$variable])) {
            $this->images[$variable] = array();
        }

        $this->images[$variable][] = $node;
    }

    /**
     * Template method that performs the real node image check.
     *
     * @param ASTNode $node
     * @return void
     */
    protected function doCheckNodeImage(ASTNode $node)
    {
        if ($this->isNameAllowedInContext($node)) {
            return;
        }
        if ($this->isUnusedForeachVariableAllowed($node)) {
            return;
        }
        $exceptions = $this->getExceptionsList();
        if (in_array(substr($node->getImage(), 1), $exceptions)) {
            return;
        }
        $this->addViolation($node, array($node->getImage()));
    }

    /**
     * Checks if a short name is acceptable in the current context. For the
     * moment these contexts are the init section of a for-loop and short
     * variable names in catch-statements.
     *
     * @param \PHPMD\AbstractNode $node
     * @return boolean
     */
    private function isNameAllowedInContext(AbstractNode $node)
    {
        return $this->isChildOf($node, 'CatchStatement');
    }

    /**
     * Checks if an unused foreach variable (key or variable) is allowed.
     *
     * If it's not a foreach variable, it returns always false.
     *
     * @param \PHPMD\Node\ASTNode $variable The variable to check.
     * @return bool True if allowed, else false.
     */
    private function isUnusedForeachVariableAllowed(ASTNode $variable)
    {
        $isForeachVariable = $this->isChildOf($variable, 'ForeachStatement');

        if (!$isForeachVariable) {
            return false;
        }

        return $this->getBooleanProperty('allow-unused-foreach-variables');
    }

    /**
     * Checks if the given node is a direct or indirect child of a node with
     * the given type.
     *
     * @param \PHPMD\AbstractNode $node
     * @param string $type
     * @return boolean
     */
    private function isChildOf(AbstractNode $node, $type)
    {
        $parent = $node->getParent();

        return $parent->isInstanceOf($type);
    }

    /**
     * Gets array of exceptions from property
     *
     * @return array
     */
    private function getExceptionsList()
    {
        return explode(',', $this->getStringProperty('exceptions', ''));
    }
}
