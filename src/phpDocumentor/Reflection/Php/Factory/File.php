<?php
/**
 * This file is part of phpDocumentor.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @copyright 2010-2015 Mike van Riel<mike@phpdoc.org>
 * @license   http://www.opensource.org/licenses/mit-license.php MIT
 * @link      http://phpdoc.org
 */


namespace phpDocumentor\Reflection\Php\Factory;

use InvalidArgumentException;
use phpDocumentor\Reflection\Fqsen;
use phpDocumentor\Reflection\Php\File as FileElement;
use phpDocumentor\Reflection\Php\NodesFactory;
use phpDocumentor\Reflection\Php\ProjectFactoryStrategy;
use phpDocumentor\Reflection\Php\StrategyContainer;
use phpDocumentor\Reflection\Types\Context;
use phpDocumentor\Reflection\Types\ContextFactory;
use PhpParser\Comment\Doc;
use PhpParser\Lexer;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_ as ClassNode;
use PhpParser\Node\Stmt\Function_ as FunctionNode;
use PhpParser\Node\Stmt\Interface_ as InterfaceNode;
use PhpParser\Node\Stmt\Namespace_ as NamespaceNode;
use PhpParser\Node\Stmt\Trait_ as TraitNode;

/**
 * Strategy to create File element from the provided filename.
 */
final class File implements ProjectFactoryStrategy
{
    /**
     * @var NodesFactory
     */
    private $nodesFactory;

    /**
     * Initializes the object
     * @param NodesFactory $nodesFactory
     */
    public function __construct(NodesFactory $nodesFactory)
    {
        $this->nodesFactory = $nodesFactory;
    }

    /**
     * Returns true when the strategy is able to handle the object.
     *
     * @param string $filePath path to check.
     * @return boolean
     */
    public function matches($filePath)
    {
        return is_string($filePath) && file_exists($filePath);
    }

    /**
     * Creates an File out of the given object.
     * Since an object might contain other objects that need to be converted the $factory is passed so it can be
     * used to create nested Elements.
     *
     * @param string $object path to the file to convert to an File object.
     * @param StrategyContainer $strategies used to convert nested objects.
     * @param Context $context
     * @return File
     */
    public function create($object, StrategyContainer $strategies, Context $context = null)
    {
        if (!$this->matches($object)) {
            throw new InvalidArgumentException(
                sprintf('%s cannot handle objects with the type %s',
                    __CLASS__,
                    is_object($object) ? get_class($object) : gettype($object)
                )
            );
        }
        $code = file_get_contents($object);
        $nodes = $this->nodesFactory->create($code);
        $docBlock = $this->createDocBlock($strategies, $code, $nodes);

        $file = new FileElement(md5_file($object), $object, $code, $docBlock);

        $this->createElements(new Fqsen('\\'), $nodes, $file, $strategies);

        return $file;
    }

    /**
     * @param Fqsen $namespace
     * @param Node[] $nodes
     * @param FileElement $file
     * @param StrategyContainer $strategies
     */
    private function createElements(Fqsen $namespace, $nodes, FileElement $file, StrategyContainer $strategies)
    {
        $contextFactory = new ContextFactory();
        $context = $contextFactory->createForNamespace((string)$namespace, $file->getSource());
        foreach ($nodes as $node) {
            switch (get_class($node)) {
                case ClassNode::class:
                    $strategy = $strategies->findMatching($node);
                    $class = $strategy->create($node, $strategies, $context);
                    $file->addClass($class);
                    break;
                case FunctionNode::class:
                    $strategy = $strategies->findMatching($node);
                    $function = $strategy->create($node, $strategies, $context);
                    $file->addFunction($function);
                    break;
                case InterfaceNode::class:
                    $strategy = $strategies->findMatching($node);
                    $interface = $strategy->create($node, $strategies, $context);
                    $file->addInterface($interface);
                    break;
                case NamespaceNode::class:
                    $file->addNamespace($node->fqsen);
                    $this->createElements($node->fqsen, $node->stmts, $file, $strategies);
                    break;
                case TraitNode::class:
                    $strategy = $strategies->findMatching($node);
                    $trait = $strategy->create($node, $strategies, $context);
                    $file->addTrait($trait);
                    break;
            }
        }
    }

    /**
     * @param StrategyContainer $strategies
     * @param $code
     * @param $nodes
     * @return null|\phpDocumentor\Reflection\Element
     * @internal param Context $context
     */
    private function createDocBlock(StrategyContainer $strategies, $code, $nodes)
    {
        $contextFactory = new ContextFactory();
        $context = $contextFactory->createForNamespace('\\', $code);
        $docBlock = null;

        foreach ($nodes as $node) {
            if ($node instanceof Doc) {
                $strategy = $strategies->findMatching($node);
                $docBlock = $strategy->create($node, $strategies, $context);
                break;
            }
        }

        return $docBlock;
    }
}
