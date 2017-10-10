<?php

namespace DNADesign\Elemental;

use SilverStripe\Core\Extensible;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldAddExistingAutocompleter;
use SilverStripe\Forms\GridField\GridFieldAddNewButton;
use SilverStripe\Forms\GridField\GridFieldConfig_RelationEditor;
use SilverStripe\Forms\GridField\GridFieldDeleteAction;
use SilverStripe\Forms\GridField\GridFieldPaginator;
use SilverStripe\Forms\GridField\GridFieldSortableHeader;
use SilverStripe\Forms\GridField\GridFieldPageCount;
use SilverStripe\Core\Injector\Injector;
use DNADesign\Elemental\Models\ElementalArea;
use DNADesign\Elemental\Forms\ElementalGridFieldAddNewMultiClass;
use Symbiote\GridFieldExtensions\GridFieldOrderableRows;

class ElementalEditor
{
    use Extensible;
    use Injectable;

    /**
     * @var ElementalArea $area
     */
    protected $area;

    /**
     * @var string $name
     */
    protected $name;

    /**
     * By default, no need for a title on the editor. If there is more than one
     * area then use `setTitle` to describe.
     *
     * @var string $title
     */
    protected $title = '';

    /**
     * @var array $type
     */
    protected $types = [];

    /**
     * @param string $name
     * @param ElementalArea $area
     */
    public function __construct($name, ElementalArea $area)
    {
        $this->name = $name;
        $this->area = $area;
    }

    /**
     * @param array $types
     *
     * @return $this
     */
    public function setTypes($types)
    {
        $this->types = $types;

        return $this;
    }

    /**
     * @return ElementalArea
     */
    public function getArea()
    {
        return $this->area;
    }

    /**
     * @param string $title
     *
     * @return $this
     */
    public function setTitle($title)
    {
        $this->title = $title;

        return $this;
    }

    /**
     * @return GridField
     */
    public function getField()
    {
        $gridField = GridField::create(
            $this->name,
            $this->title,
            $this->getArea()->Elements(),
            $config = GridFieldConfig_RelationEditor::create()
                ->removeComponentsByType(array(
                    GridFieldAddNewButton::class,
                    GridFieldSortableHeader::class,
                    GridFieldDeleteAction::class,
                    GridFieldPaginator::class,
                    GridFieldPageCount::class,
                    GridFieldAddExistingAutocompleter::class
                ))
                ->addComponent(new GridFieldOrderableRows('Sort'))
                ->addComponent(new GridFieldDeleteAction(true))
        );

        if ($this->types) {
            $adder = Injector::inst()->create(ElementalGridFieldAddNewMultiClass::class, 'toolbar-header-left');
            $adder->setClasses($this->types);

            $config->addComponent($adder);
        }

        $this->extend('updateField', $gridField);

        return $gridField;
    }
}
