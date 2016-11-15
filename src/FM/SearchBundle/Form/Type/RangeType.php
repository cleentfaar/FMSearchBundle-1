<?php

namespace FM\SearchBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class RangeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $defaultOptions = array(
            'required' => $options['required'],
            'error_bubbling' => true,
        );

        $startOptions = array_merge(
            $defaultOptions,
            array('label' => $options['label'].'_start'),
            $options['start_options']
        );

        $endOptions = array_merge(
            $defaultOptions,
            array('label' => $options['label'].'_end'),
            $options['end_options']
        );

        $builder->add('start', $options['type'], $startOptions);
        $builder->add('end', $options['type'], $endOptions);
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'type' => 'choice',
            'start_options' => array(),
            'end_options' => array(),
            'compound' => true,
            'empty_value' => 'Choose a value',
        ));

        $resolver->setAllowedTypes('type', array('string'));
        $resolver->setAllowedTypes('start_options', array('array'));
        $resolver->setAllowedTypes('end_options', array('array'));
    }

    public function getParent()
    {
        return 'form';
    }

    public function getName()
    {
        return 'range';
    }
}
