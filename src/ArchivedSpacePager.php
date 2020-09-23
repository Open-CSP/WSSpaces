<?php

namespace PDP;

use MediaWiki\MediaWikiServices;

class ArchivedSpacePager extends PDPPager {
    /**
     * This function should be overridden to provide all parameters
     * needed for the main paged query. It returns an associative
     * array with the following elements:
     *    tables => Table(s) for passing to Database::select()
     *    fields => Field(s) for passing to Database::select(), may be *
     *    conds => WHERE conditions
     *    options => option array
     *    join_conds => JOIN conditions
     *
     * @return array
     */
    public function getQueryInfo() {
        return [
            'tables' => 'pdp_namespaces',
            'fields' => [
                'namespace_name',
                'display_name',
                'description'
            ],
            'conds' => [
                'archived' => true
            ]
        ];
    }

    /**
     * Return true if the named field should be sortable by the UI, false
     * otherwise
     *
     * @param string $field
     * @return bool
     */
    public function isFieldSortable( $field ) {
        switch ( $field ) {
            case 'namespace_name':
            case 'display_name':
                return true;
            default:
                return false;
        }
    }

    /**
     * Format a table cell. The return value should be HTML, but use an empty
     * string not &#160; for empty cells. Do not include the <td> and </td>.
     *
     * The current result row is available as $this->mCurrentRow, in case you
     * need more context.
     *
     * @protected
     *
     * @param string $name The database field name
     * @param string $value The value retrieved from the database
     * @return string
     */
    public function formatValue( $name, $value ) {
        $value = htmlspecialchars( $value );

        switch ( $name ) {
            case 'namespace_name':
                $link_renderer = MediaWikiServices::getInstance()->getLinkRenderer();
                $title = $this->getTitle();

                $page = \Title::newFromText( $title->getText() . "/$value", NS_SPECIAL );

                return $link_renderer->makeLink( $page, new \HtmlArmor( $value ) );
            default:
                return $value;
        }
    }

    /**
     * The database field name used as a default sort order.
     *
     * @protected
     *
     * @return string
     */
    public function getDefaultSort() {
        return 'namespace_name';
    }

    public function getDefaultDirections() {
        return parent::DIR_DESCENDING;
    }

    /**
     * An array mapping database field names to a textual description of the
     * field name, for use in the table header. The description should be plain
     * text, it will be HTML-escaped later.
     *
     * @return array
     */
    public function getFieldNames() {
        return [
            'namespace_name' => "Namespace",
            'display_name' => "Display Name",
            'description' => "Description"
        ];
    }

    /**
     * @return string
     */
    public function getTableClass() {
        return 'pdp-table TablePager';
    }
}