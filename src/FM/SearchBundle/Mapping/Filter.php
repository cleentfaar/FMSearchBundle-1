<?php

namespace FM\SearchBundle\Mapping;

use Solarium\Core\Query\Helper;
use Solarium\QueryType\Select\Query\FilterQuery;

use FM\SearchBundle\Mapping\Config;
use FM\SearchBundle\Mapping\Filter\Type;

class Filter
{
    private $operators = array(
        'AND',
        'OR'
    );

    private $name;
    private $field;
    private $type;
    private $config;
    private $choices;
    private $facet;
    private $operator;
    private $label;
    private $helper;

    public function __construct($name, Field $field, Type $type, array $config)
    {
        $this->name = $name;
        $this->field = $field;
        $this->type = $type;
        $this->config = new Config($config);

        $this->init($config);
    }

    protected function init(array $config)
    {
        $this->operator = strtoupper($this->config->get('operator', 'OR'));

        if (!in_array($this->operator, $this->operators)) {
            throw new \InvalidArgumentException(sprintf('Invalid operator "%s"', $this->operator));
        }

        if ($this->config->has('choices')) {
            $choices = $this->config->get('choices');

            if (!empty($choices)) {

                $this->choices = $choices;

                // if array values are all numeric, assume a non-associative array
                // is passed, and convert the choices.
                $associative = false;
                foreach ($this->choices as $value) {
                    if (!is_numeric($value)) {
                        $associative = true;
                        break;
                    }
                }

                if (!$associative) {
                    $choices = array_values($this->choices);
                    $this->choices = array_combine($choices, $choices);
                }
            }
        }

        $this->label = $this->config->get('label');
    }

    public function getName()
    {
        return $this->name;
    }

    public function getField()
    {
        return $this->field;
    }

    public function getType()
    {
        return $this->type;
    }

    public function getConfig()
    {
        return $this->config;
    }

    public function getOperator()
    {
        return $this->operator;
    }

    public function getChoices()
    {
        return $this->choices;
    }

    public function hasChoices()
    {
        return !is_null($this->choices);
    }

    public function getHelper()
    {
        if (is_null($this->helper)) {
            $this->helper = new Helper();
        }

        return $this->helper;
    }

    public function getLabel()
    {
        return $this->label;
    }

    public function setFacet(Facet $facet)
    {
        $facet->setFilter($this);
        $this->facet = $facet;
    }

    public function getFacet()
    {
        return $this->facet;
    }

    /**
     * Renders the label for a choice. You can set `choice_label` in the filter
     * config to use a closure, if you want to apply custom logic for this.
     *
     * @param  string $value   The choice value
     * @param  string $default The default label for the choice
     * @return string
     */
    public function getChoiceLabel($value, $default)
    {
        if ($this->config->has('choice_label')) {

            $callback = $this->config->get('choice_label');

            if (!($callback instanceof \Closure)) {
                throw new \LogicException('Expecting a Closure instance for choice_label config');
            }

            return $callback($value, $default);
        }

        return $default;
    }

    /**
     * Transforms choices into placeholders to be used in Solr queries.
     *
     * @param  array       $choices
     * @param  FilterQuery $query
     * @return array
     */
    public function transformChoice($choice, FilterQuery $query)
    {
        $choice = $this->type->normalizeChoice($this, $choice);

        if ($choice instanceof \Closure) {
            $choice = call_user_func_array($choice, array($query));
        }

        return $choice;
    }

    public function getChoice($value)
    {
        if (null !== $choice = $this->type->getChoice($this, $value)) {
            return $choice;
        }

        throw new \OutOfBoundsException(sprintf('"%s" is not a valid choice', $value));
    }

    /**
     * Sets query for this particular filter.
     *
     * @param FilterQuery $query The filterQuery used in the main query
     * @param mixed       $value The filter value
     */
    public function setQuery(FilterQuery $query, $value)
    {
        if (!$this->isValidValue($value)) {
            throw new \UnexpectedValueException('Expected a non-empty array with values for filter');
        }

        // cast to array if neccesary
        if (is_scalar($value)) {
            $value = array($value);
        }

        // Put in new array if the filter expects multiple values, and the
        // given array is only 1 level deep. But don't do this for choices,
        // which should translate into array values.
        if ($this->type->isMultiValued() && !$this->hasChoices() && !is_array(current($value))) {
            $value = array($value);
        }

        // Type is a non-predefined range, thus expecting an array for each
        // filter. But array is only 1 level deep, and we want to be able to
        // filter more than one range, so nest it 1 level deeper.
        if (($this->type instanceof Type\Range) && !$this->type->isPredefined($this) && !is_array(current($value))) {
            $value = array($value);
        }

        $parts = array();
        $name = $this->field->getName();

        // At this point, $value is always an array, with each entry containing
        // one instance of the filter. This way, we ensure the same structure
        // for each filter.
        foreach ($value as $queryValue) {
            if ($this->hasChoices()) {
                // transform choices into query value
                $queryValue = $this->transformChoice($queryValue, $query);
            } else {
                // not a choice, transform value
                $queryValue = $this->transformValue($queryValue);
            }

            if (!empty($queryValue)) {
                // make sure query value is an array, as getQuery depends on it
                $parts[] = $this->getQuery((array) $queryValue);
            }
        }

        if (!empty($parts)) {
            $query->setQuery(implode(sprintf(' %s ', $this->operator), $parts));
        }
    }

    protected function getQuery(array $value)
    {
        $query = $this->type->getQuery($this->field->getName());

        return $this->getHelper()->assemble($query, array_values($value));
    }

    /**
     * Returns whether the untransformed value is valid for this filter. For
     * example: empty strings or arrays are not, whereas 0 is valid.
     *
     * @param  mixed   $value
     * @return boolean
     */
    public function isValidValue($value)
    {
        // null value
        if (is_null($value)) {
            return false;
        }

        // empty string
        if (is_string($value)) {
            return $value !== '';
        }

        // empty arrays
        if (is_array($value)) {
            return !empty($value);
        }

        return true;
    }

    /**
     * Transforms value into a value that's appropriate for Solr, according to
     * the field type. Arrays are transformed recursively.
     *
     * @param  mixed $value
     * @return mixed
     */
    public function transformValue($value)
    {
        if (is_array($value)) {
            foreach ($value as $k => &$v) {
                $v = $this->transformValue($v);
            }

            return $value;

        } else {
            return $this->field->getType()->convertToSolrValue($value);
        }
    }
}
