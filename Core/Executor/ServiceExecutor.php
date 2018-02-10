<?php

namespace Kaliop\eZMigrationBundle\Core\Executor;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Kaliop\eZMigrationBundle\API\Value\MigrationStep;
use Kaliop\eZMigrationBundle\Core\ReferenceResolver\PrefixBasedResolverInterface;

class ServiceExecutor extends AbstractExecutor
{
    protected $supportedStepTypes = array('service');
    protected $supportedActions = array('call');

    /** @var PrefixBasedResolverInterface $referenceResolver */
    protected $referenceResolver;

    protected $container;

    public function __construct(ContainerInterface $container, PrefixBasedResolverInterface $referenceResolver)
    {
        $this->referenceResolver = $referenceResolver;
        $this->container = $container;
    }

    /**
     * @param MigrationStep $step
     * @return mixed
     * @throws \Exception
     */
    public function execute(MigrationStep $step)
    {
        parent::execute($step);

        if (!isset($step->dsl['mode'])) {
            throw new \Exception("Invalid step definition: missing 'mode'");
        }

        $action = $step->dsl['mode'];

        if (!in_array($action, $this->supportedActions)) {
            throw new \Exception("Invalid step definition: value '$action' is not allowed for 'mode'");
        }

        return $this->$action($step->dsl, $step->context);
    }

    /**
     * @param $dsl
     * @param $context
     * @return \Symfony\Component\Process\Process
     * @throws \Exception
     */
    protected function call($dsl, $context)
    {
        if (!isset($dsl['service'])) {
            throw new \Exception("Can not call service method: 'service' missing");
        }
        if (!isset($dsl['method'])) {
            throw new \Exception("Can not call service method: 'method' missing");
        }
        if (isset($dsl['arguments']) && !is_array($dsl['arguments'])) {
            throw new \Exception("Can not call service method: 'arguments' is not an array");
        }

        $service = $this->container->get($dsl['service']);
        $method = $dsl['method'];
        $callable = array($service, $method);
        if (isset($dsl['arguments'])) {
            $args = $dsl['arguments'];
        } else {
            $args = array();
        }

        if (!is_callable($callable)) {
            throw new \Exception("Can not call service method: $method is not a method of " . get_class($service));
        }

        foreach($args as &$val) {
            $val = $this->resolveReferencesRecursively($val);
        }

        try {
            $result = call_user_func_array($callable, $args);
        } catch (\Exception $e) {
            // @todo allow to specify a set of exceptions to tolerate
            if (isset($dsl['catch'])) {

            }

            throw $e;
        }

        $this->setReferences($result, $dsl);

        return $output;
    }

    protected function setReferences($result, $dsl)
    {
        if (!array_key_exists('references', $dsl)) {
            return false;
        }

        foreach ($dsl['references'] as $reference) {
            switch ($reference['attribute']) {
                case 'result':
                    $value = $result;
                    break;
                default:
                    throw new \InvalidArgumentException('Service executor does not support setting references for attribute ' . $reference['attribute']);
            }

            $overwrite = false;
            if (isset($reference['overwrite'])) {
                $overwrite = $reference['overwrite'];
            }
            $this->referenceResolver->addReference($reference['identifier'], $value, $overwrite);
        }

        return true;
    }

    protected function resolveReferencesRecursively($match)
    {
        if (is_array($match)) {
            foreach ($match as $condition => $values) {
                $match[$condition] = $this->resolveReferencesRecursively($values);
            }
            return $match;
        } else {
            return $this->referenceResolver->resolveReference($match);
        }
    }
}