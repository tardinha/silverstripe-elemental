<?php

namespace DNADesign\Elemental\Models;

use DNADesign\Elemental\Controllers\ElementController;
use DNADesign\Elemental\Forms\TextCheckboxGroupField;
use Exception;
use SilverStripe\CMS\Controllers\CMSPageEditController;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\HiddenField;
use SilverStripe\Forms\ListboxField;
use SilverStripe\Forms\NumericField;
use SilverStripe\Forms\OptionsetField;
use SilverStripe\Forms\TextField;
use SilverStripe\Forms\TreeMultiselectField;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\Security\Security;
use SilverStripe\Security\Group;
use SilverStripe\Security\InheritedPermissions;
use SilverStripe\Security\InheritedPermissionsExtension;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Versioned\Versioned;
use SilverStripe\VersionedAdmin\Forms\HistoryViewerField;
use SilverStripe\View\ArrayData;
use SilverStripe\View\Parsers\URLSegmentFilter;
use SilverStripe\View\Requirements;

/**
 * Class BaseElement
 * @package DNADesign\Elemental\Models
 *
 * @property string $Title
 * @property bool $ShowTitle
 * @property int $Sort
 * @property string $ExtraClass
 * @property string $Style
 *
 * @method ElementalArea Parent()
 */
class BaseElement extends DataObject
{
    /**
     * Override this on your custom elements to specify a CSS icon class
     *
     * @var string
     */
    private static $icon = 'font-icon-block-layout';

    /**
     * Describe the purpose of this element
     *
     * @config
     * @var string
     */
    private static $description = 'Base element class';

    private static $db = [
        'Title' => 'Varchar(255)',
        'ShowTitle' => 'Boolean',
        'Sort' => 'Int',
        'ExtraClass' => 'Varchar(255)',
        'Style' => 'Varchar(255)'
    ];

    private static $has_one = [
        'Parent' => ElementalArea::class
    ];

    private static $many_many = [
        'ViewerGroups' => Group::class,
    ];

    private static $extensions = [
        Versioned::class,
        InheritedPermissionsExtension::class
    ];

    private static $versioned_gridfield_extensions = true;

    private static $table_name = 'Element';

    /**
     * @var string
     */
    private static $controller_class = ElementController::class;

    /**
     * @var string
     */
    private static $controller_template = 'ElementHolder';

    /**
     * @var ElementController
     */
    protected $controller;

    private static $default_sort = 'Sort';

    private static $singular_name = 'block';

    private static $plural_name = 'blocks';

    private static $summary_fields = [
        'EditorPreview' => 'Summary'
    ];

    /**
     * @config
     * @var array
     */
    private static $styles = [];

    private static $searchable_fields = [
        'ID' => [
            'field' => NumericField::class,
        ],
        'Title',
        'LastEdited'
    ];

    /**
     * Enable for backwards compatibility
     *
     * @var boolean
     */
    private static $disable_pretty_anchor_name = false;

    /**
     * Store used anchor names, this is to avoid title clashes
     * when calling 'getAnchor'
     *
     * @var array
     */
    protected static $used_anchors = [];

    /**
     * For caching 'getAnchor'
     *
     * @var string
     */
    protected $anchor = null;

    /**
     * Basic permissions, defaults to page perms where possible.
     *
     * @param Member $member
     * @return boolean
     */
/*
    public function canView($member = null)
    {
        $extended = $this->extendedCan(__FUNCTION__, $member);
        if ($extended !== null) {
            return $extended;
        }

        if ($this->hasMethod('getPage')) {
            if ($page = $this->getPage()) {
                return $page->canView($member);
            }
        }

        return (Permission::check('CMS_ACCESS', 'any', $member)) ? true : null;
    }
*/

    /**
     * This function should return true if the current user can view this element.
     *
     * Denies permission if any of the following conditions is true:
     * - canView() on any extension returns false
     * - "CanViewType" directive is set to "LoggedInUsers" and no user is logged in
     * - "CanViewType" directive is set to "OnlyTheseUsers" and user is not in the given groups
     *
     * @uses DataExtension->canView()
     * @uses ViewerGroups()
     *
     * @param Member $member
     * @return bool True if the current user can view this page
     */
    public function canView($member = null)
    {
        if (!$member) {
            $member = Security::getCurrentUser();
        }

        // Standard mechanism for accepting permission changes from extensions
        $extended = $this->extendedCan('canView', $member);
        if ($extended !== null) {
            return $extended;
        }

        // admin override
        if ($member && Permission::checkMember($member, array("ADMIN", "SITETREE_VIEW_ALL"))) {
            return true;
        }

        // Note: getInheritedPermissions() is disused in this instance
        // to allow parent canView extensions to influence canView()

        // check for empty spec
        if (!$this->CanViewType || $this->CanViewType === InheritedPermissions::ANYONE) {
            return true;
        }

        // check for any logged-in users
        if ($this->CanViewType === InheritedPermissions::LOGGED_IN_USERS && $member && $member->ID) {
            return true;
        }

        // check for specific groups
        if ($this->CanViewType === InheritedPermissions::ONLY_THESE_USERS
            && $member
            && $member->inGroups($this->ViewerGroups())
        ) {
            return true;
        }

        return false;
    }

    /**
     * Basic permissions, defaults to page perms where possible.
     *
     * @param Member $member
     *
     * @return boolean
     */
    public function canEdit($member = null)
    {
        $extended = $this->extendedCan(__FUNCTION__, $member);
        if ($extended !== null) {
            return $extended;
        }

        if ($this->hasMethod('getPage')) {
            if ($page = $this->getPage()) {
                return $page->canEdit($member);
            }
        }

        return (Permission::check('CMS_ACCESS', 'any', $member)) ? true : null;
    }

    /**
     * Basic permissions, defaults to page perms where possible.
     *
     * Uses archive not delete so that current stage is respected i.e if a
     * element is not published, then it can be deleted by someone who doesn't
     * have publishing permissions.
     *
     * @param Member $member
     *
     * @return boolean
     */
    public function canDelete($member = null)
    {
        $extended = $this->extendedCan(__FUNCTION__, $member);
        if ($extended !== null) {
            return $extended;
        }

        if ($this->hasMethod('getPage')) {
            if ($page = $this->getPage()) {
                return $page->canArchive($member);
            }
        }

        return (Permission::check('CMS_ACCESS', 'any', $member)) ? true : null;
    }

    /**
     * Basic permissions, defaults to page perms where possible.
     *
     * @param Member $member
     * @param array $context
     *
     * @return boolean
     */
    public function canCreate($member = null, $context = array())
    {
        $extended = $this->extendedCan(__FUNCTION__, $member);
        if ($extended !== null) {
            return $extended;
        }

        return (Permission::check('CMS_ACCESS', 'any', $member)) ? true : null;
    }

    /**
     * Increment the sort order if one hasn't been already defined. This ensures that new elements are created
     * at the end of the list by default.
     *
     * {@inheritDoc}
     */
    public function onBeforeWrite()
    {
        parent::onBeforeWrite();

        if (!$this->Sort) {
            $this->Sort = static::get()->max('Sort') + 1;
        }
    }

    public function getCMSFields()
    {
        $this->beforeUpdateCMSFields(function (FieldList $fields) {
            // Remove relationship fields
            $fields->removeByName('ParentID');
            $fields->removeByName('Sort');

            $fields->addFieldToTab(
                'Root.Settings',
                TextField::create('ExtraClass', _t(__CLASS__ . '.ExtraCssClassesLabel', 'Custom CSS classes'))
                    ->setAttribute(
                        'placeholder',
                        _t(__CLASS__ . '.ExtraCssClassesPlaceholder', 'my_class another_class')
                    )
            );

            // Add a combined field for "Title" and "Displayed" checkbox in a Bootstrap input group
            $fields->removeByName('ShowTitle');
            $fields->replaceField(
                'Title',
                TextCheckboxGroupField::create(
                    TextField::create('Title', _t(__CLASS__ . '.TitleLabel', 'Title (displayed if checked)')),
                    CheckboxField::create('ShowTitle', _t(__CLASS__ . '.ShowTitleLabel', 'Displayed'))
                )
                    ->setName('TitleAndDisplayed')
            );

            // Rename the "Main" tab
            $fields->fieldByName('Root.Main')
                ->setTitle(_t(__CLASS__ . '.MainTabLabel', 'Content'));

            $fields->addFieldsToTab('Root.Main', [
                HiddenField::create('AbsoluteLink', false, Director::absoluteURL($this->PreviewLink())),
                HiddenField::create('LiveLink', false, Director::absoluteURL($this->Link())),
                HiddenField::create('StageLink', false, Director::absoluteURL($this->PreviewLink())),
            ]);

            $styles = $this->config()->get('styles');

            if ($styles && count($styles) > 0) {
                $styleDropdown = DropdownField::create('Style', _t(__CLASS__.'.STYLE', 'Style variation'), $styles);

                $fields->insertBefore($styleDropdown, 'ExtraClass');

                $styleDropdown->setEmptyString(_t(__CLASS__.'.CUSTOM_STYLES', 'Select a style..'));
            } else {
                $fields->removeByName('Style');
            }

            // Support for new history viewer in SS 4.2+
            if (class_exists(HistoryViewerField::class)) {
                Requirements::javascript('dnadesign/silverstripe-elemental:client/dist/js/bundle.js');

                $historyViewer = HistoryViewerField::create('ElementHistory');
                $fields->addFieldToTab('Root.History', $historyViewer);

                $fields->fieldByName('Root.History')
                    ->addExtraClass('elemental-block__history-tab tab--history-viewer');
            }

            // Viewer groups
            $fields->removeByName('ViewerGroups');
            $fields->removeByName('EditorGroups');

            $viewersOptionsField = OptionsetField::create(
                "CanViewType",
                _t(__CLASS__.'.ACCESSHEADER', "Who can view this page?")
            );

            $viewerGroupsField = TreeMultiselectField::create(
                "ViewerGroups",
                _t(__CLASS__.'.VIEWERGROUPS', "Viewer Groups"),
                Group::class
            );

            $viewersOptionsSource = [
                InheritedPermissions::ANYONE => _t(__CLASS__.'.ACCESSANYONE', "Anyone"),
                InheritedPermissions::LOGGED_IN_USERS => _t(__CLASS__.'.ACCESSLOGGEDIN', "Logged-in users"),
                InheritedPermissions::ONLY_THESE_USERS => _t(
                    __CLASS__.'.ACCESSONLYTHESE',
                    "Only these groups (choose from list)"
                ),
            ];

            $viewersOptionsField->setSource($viewersOptionsSource)->setValue('Anyone');

            $fields->addFieldsToTab('Root.ViewerGroups', [
                $viewersOptionsField,
                $viewerGroupsField,
            ]);

        });

        return parent::getCMSFields();
    }

    /**
     * Get the type of the current block, for use in GridField summaries, block
     * type dropdowns etc. Examples are "Content", "File", "Media", etc.
     *
     * @return string
     */
    public function getType()
    {
        return _t(__CLASS__ . '.BlockType', 'Block');
    }

    /**
     * @param ElementController $controller
     *
     * @return $this
     */
    public function setController($controller)
    {
        $this->controller = $controller;

        return $this;
    }

    /**
     * @throws Exception If the specified controller class doesn't exist
     *
     * @return ElementController
     */
    public function getController()
    {
        if ($this->controller) {
            return $this->controller;
        }

        $controllerClass = self::config()->controller_class;

        if (!class_exists($controllerClass)) {
            throw new Exception(
                'Could not find controller class ' . $controllerClass . ' as defined in ' . static::class
            );
        }

        $this->controller = Injector::inst()->create($controllerClass, $this);
        $this->controller->doInit();

        return $this->controller;
    }

    /**
     * @return Controller
     */
    public function Top()
    {
        return (Controller::has_curr()) ? Controller::curr() : null;
    }

    /**
     * Default way to render element in templates. Note that all blocks should
     * be rendered through their {@link ElementController} class as this
     * contains the holder styles.
     *
     * @return string|null HTML
     */
    public function forTemplate($holder = true)
    {
        $templates = $this->getRenderTemplates();

        if ($templates) {
            return $this->renderWith($templates);
        }

        return null;
    }

    /**
     * @param string $suffix
     *
     * @return array
     */
    public function getRenderTemplates($suffix = '')
    {
        $classes = ClassInfo::ancestry($this->ClassName);
        $classes[static::class] = static::class;
        $classes = array_reverse($classes);
        $templates = [];

        foreach ($classes as $key => $class) {
            if ($class == BaseElement::class) {
                continue;
            }

            if ($class == DataObject::class) {
                break;
            }

            if ($style = $this->Style) {
                $templates[$class][] = $class . $suffix . '_'. $this->getAreaRelationName() . '_' . $style;
                $templates[$class][] = $class . $suffix . '_' . $style;
            }
            $templates[$class][] = $class . $suffix . '_'. $this->getAreaRelationName();
            $templates[$class][] = $class . $suffix;
        }

        $this->extend('updateRenderTemplates', $templates, $suffix);

        $templateFlat = [];
        foreach ($templates as $class => $variations) {
            $templateFlat = array_merge($templateFlat, $variations);
        }

        return $templateFlat;
    }

    /**
     * Strip all namespaces from class namespace.
     *
     * @param string $classname e.g. "\Fully\Namespaced\Class"
     *
     * @return string following the param example, "Class"
     */
    protected function stripNamespacing($classname)
    {
        $classParts = explode('\\', $classname);
        return array_pop($classParts);
    }

    /**
     * @return string
     */
    public function getSimpleClassName()
    {
        return strtolower($this->sanitiseClassName($this->ClassName, '__'));
    }

    /**
     * @return null|DataObject
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \SilverStripe\ORM\ValidationException
     */
    public function getPage()
    {
        $area = $this->Parent();

        if ($area instanceof ElementalArea && $area->exists()) {
            return $area->getOwnerPage();
        }

        return null;
    }

    /**
     * Get a unique anchor name
     *
     * @return string
     */
    public function getAnchor()
    {
        if ($this->anchor !== null) {
            return $this->anchor;
        }

        $anchorTitle = '';

        if (!$this->config()->disable_pretty_anchor_name) {
            if ($this->hasMethod('getAnchorTitle')) {
                $anchorTitle = $this->getAnchorTitle();
            } elseif ($this->config()->enable_title_in_template) {
                $anchorTitle = $this->getField('Title');
            }
        }

        if (!$anchorTitle) {
            $anchorTitle = 'e'.$this->ID;
        }

        $filter = URLSegmentFilter::create();
        $titleAsURL = $filter->filter($anchorTitle);

        // Ensure that this anchor name isn't already in use
        // ie. If two elemental blocks have the same title, it'll append '-2', '-3'
        $result = $titleAsURL;
        $count = 1;
        while (isset(self::$used_anchors[$result]) && self::$used_anchors[$result] !== $this->ID) {
            ++$count;
            $result = $titleAsURL . '-' . $count;
        }
        self::$used_anchors[$result] = $this->ID;
        return $this->anchor = $result;
    }

    /**
     * @param string|null $action
     * @return string|null
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \SilverStripe\ORM\ValidationException
     */
    public function AbsoluteLink($action = null)
    {
        if ($page = $this->getPage()) {
            $link = $page->AbsoluteLink($action) . '#' . $this->getAnchor();

            return $link;
        }

        return null;
    }

    /**
     * @param string|null $action
     * @return string
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \SilverStripe\ORM\ValidationException
     */
    public function Link($action = null)
    {
        if ($page = $this->getPage()) {
            $link = $page->Link($action) . '#' . $this->getAnchor();

            $this->extend('updateLink', $link);

            return $link;
        }

        return null;
    }

    /**
     * @param string|null $action
     * @return string
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \SilverStripe\ORM\ValidationException
     */
    public function PreviewLink($action = null)
    {
        $action = $action . '?ElementalPreview=' . mt_rand();
        $link = $this->Link($action);
        $this->extend('updatePreviewLink', $link);

        return $link;
    }

    /**
     * @return boolean
     */
    public function isCMSPreview()
    {
        if (Controller::has_curr()) {
            $controller = Controller::curr();

            if ($controller->getRequest()->requestVar('CMSPreview')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return null|string
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \SilverStripe\ORM\ValidationException
     */
    public function CMSEditLink()
    {
        $relationName = $this->getAreaRelationName();
        $page = $this->getPage(true);

        if (!$page) {
            return null;
        }

        $editLinkPrefix = '';
        if (!$page instanceof SiteTree && method_exists($page, 'CMSEditLink')) {
            $editLinkPrefix = Controller::join_links($page->CMSEditLink(), 'ItemEditForm');
        } else {
            $editLinkPrefix = Controller::join_links(
                singleton(CMSPageEditController::class)->Link('EditForm'),
                $page->ID
            );
        }

        $link = Controller::join_links(
            $editLinkPrefix,
            'field/' . $relationName . '/item/',
            $this->ID
        );

        $link = Controller::join_links(
            $link,
            'edit'
        );

        $this->extend('updateCMSEditLink', $link);

        return $link;
    }

    /**
     * Retrieve a elemental area relation for creating cms links
     *
     * @return int|string The name of a valid elemental area relation
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \SilverStripe\ORM\ValidationException
     */
    public function getAreaRelationName()
    {
        $page = $this->getPage();

        if ($page) {
            $has_one = $page->config()->get('has_one');
            $area = $this->Parent();

            foreach ($has_one as $relationName => $relationClass) {
                if ($page instanceof BaseElement && $relationName === 'Parent') {
                    continue;
                }
                if ($relationClass === $area->ClassName && $page->{$relationName}()->ID === $area->ID) {
                    return $relationName;
                }
            }
        }

        return 'ElementalArea';
    }

    /**
     * Sanitise a model class' name for inclusion in a link.
     *
     * @return string
     */
    public function sanitiseClassName($class, $delimiter = '-')
    {
        return str_replace('\\', $delimiter, $class);
    }

    public function unsanitiseClassName($class, $delimiter = '-')
    {
        return str_replace($delimiter, '\\', $class);
    }

    /**
     * @return null|string
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \SilverStripe\ORM\ValidationException
     */
    public function getEditLink()
    {
        return $this->CMSEditLink();
    }

    /**
     * @return DBField|null
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \SilverStripe\ORM\ValidationException
     */
    public function PageCMSEditLink()
    {
        if ($page = $this->getPage()) {
            return DBField::create_field('HTMLText', sprintf(
                '<a href="%s">%s</a>',
                $page->CMSEditLink(),
                $page->Title
            ));
        }

        return null;
    }

    /**
     * @return string
     */
    public function getMimeType()
    {
        return 'text/html';
    }

    /**
     * This can be overridden on child elements to create a summary for display
     * in GridFields.
     *
     * @return string
     */
    public function getSummary()
    {
        return '';
    }


    /**
     * Generate markup for element type icons suitable for use in GridFields.
     *
     * @return null|DBHTMLText
     */
    public function getIcon()
    {
        $data = ArrayData::create([]);

        $iconClass = $this->config()->get('icon');
        if ($iconClass) {
            $data->IconClass = $iconClass;

            // Add versioned states (rendered as a circle over the icon)
            if ($this->hasExtension(Versioned::class)) {
                $data->IsVersioned = true;
                if ($this->isOnDraftOnly()) {
                    $data->VersionState = 'draft';
                    $data->VersionStateTitle = _t(
                        'SilverStripe\\Versioned\\VersionedGridFieldState\\VersionedGridFieldState.ADDEDTODRAFTHELP',
                        'Item has not been published yet'
                    );
                } elseif ($this->isModifiedOnDraft()) {
                    $data->VersionState = 'modified';
                    $data->VersionStateTitle = $data->VersionStateTitle = _t(
                        'SilverStripe\\Versioned\\VersionedGridFieldState\\VersionedGridFieldState.MODIFIEDONDRAFTHELP',
                        'Item has unpublished changes'
                    );
                }
            }

            return $data->renderWith(__CLASS__ . '/PreviewIcon');
        }

        return null;
    }

    /**
     * Get a description for this content element, if available
     *
     * @return string
     */
    public function getDescription()
    {
        $description = $this->config()->uninherited('description');
        if ($description) {
            return _t(__CLASS__ . '.Description', $description);
        }
        return '';
    }

    /**
     * Generate markup for element type, with description suitable for use in
     * GridFields.
     *
     * @return DBField
     */
    public function getTypeNice()
    {
        $description = $this->getDescription();
        $desc = ($description) ? ' <span class="element__note"> &mdash; ' . $description . '</span>' : '';

        return DBField::create_field(
            'HTMLVarchar',
            $this->getType() . $desc
        );
    }

    /**
     * @return \SilverStripe\ORM\FieldType\DBHTMLText
     */
    public function getEditorPreview()
    {
        $templates = $this->getRenderTemplates('_EditorPreview');
        $templates[] = BaseElement::class . '_EditorPreview';

        return $this->renderWith($templates);
    }

    /**
     * @return Member
     */
    public function getAuthor()
    {
        if ($this->AuthorID) {
            return Member::get()->byId($this->AuthorID);
        }

        return null;
    }

    /**
     * Get a user defined style variant for this element, if available
     *
     * @return string
     */
    public function getStyleVariant()
    {
        $style = $this->Style;
        $styles = $this->config()->get('styles');

        if (isset($styles[$style])) {
            $style = strtolower($style);
        } else {
            $style = '';
        }

        $this->extend('updateStyleVariant', $style);

        return $style;
    }

    /**
     * @return mixed|null
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \SilverStripe\ORM\ValidationException
     */
    public function getPageTitle()
    {
        $page = $this->getPage();

        if ($page) {
            return $page->Title;
        }

        return null;
    }
}
