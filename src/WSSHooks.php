<?php

namespace WSS;

use ConfigException;
use Exception;
use MediaWiki\Auth\AuthManager;
use MediaWiki\Session\Session;
use MWException;
use WSS\UI\WSSUI;

abstract class WSSHooks {
    const TIMEOUT = 12000;

    /**
     * Affect the return value from AuthManager::securitySensitiveOperationStatus().
     *
     * @see https://www.mediawiki.org/wiki/Manual:Hooks/SecuritySensitiveOperationStatus
     *
     * @param string $status
     * @param string $operation
     * @param Session $session
     * @param int $timeSinceAuth
     * @return bool
     */
    public static function onSecuritySensitiveOperationStatus( string &$status, string $operation, Session $session, int $timeSinceAuth ): bool {
        $security_sensitive_operations = [
            "ws-manage-namespaces",
            "ws-create-namespaces"
        ];

        if ( $session->getLoggedOutTimestamp() > 0 ) {
            $status = AuthManager::SEC_FAIL;
        } else if ( in_array( $operation, $security_sensitive_operations, true ) && $timeSinceAuth > self::TIMEOUT ) {
            $status = AuthManager::SEC_REAUTH;
        } else {
            $status = AuthManager::SEC_OK;
        }

        return true;
    }

    /**
     * At the end of Skin::buildSidebar().
     *
     * @see https://www.mediawiki.org/wiki/Manual:Hooks/SkinBuildSidebar
     *
     * @param \Skin $skin
     * @param $bar
     * @return bool
     * @throws ConfigException
     */
    public static function onSkinBuildSidebar( \Skin $skin, &$bar ): bool {
        if ( !WSSUI::isQueued() ) {
            return true;
        }

        $bar[wfMessage('wss-sidebar-header')->plain()][] = [
            'text' => wfMessage( 'wss-add-space-header' ),
            'href' => \Title::newFromText( "AddSpace", NS_SPECIAL )->getFullUrlForRedirect(),
            'id'   => 'wss-add-space-special',
            'active' => ''
        ];

        $bar[wfMessage('wss-sidebar-header')->plain()][] = [
            'text' => wfMessage( 'wss-active-spaces-header' ),
            'href' => \Title::newFromText( "ActiveSpaces", NS_SPECIAL )->getFullUrlForRedirect(),
            'id'   => 'wss-manage-space-special',
            'active' => ''
        ];

        $bar[wfMessage('wss-sidebar-header')->plain()][] = [
            'text' => wfMessage( 'wss-archived-spaces-header' ),
            'href' => \Title::newFromText( "ArchivedSpaces", NS_SPECIAL )->getFullUrlForRedirect(),
            'id'   => 'wss-archived-spaces-special',
            'active' => ''
        ];

        WSSUI::setSidebar( $bar );

        return true;
    }

    /**
     * Fired when MediaWiki is updated to allow extensions to register updates for the database schema.
     *
     * @see https://www.mediawiki.org/wiki/Manual:Hooks/LoadExtensionSchemaUpdates
     *
     * @param \DatabaseUpdater $updater
     * @return bool
     * @throws MWException
     */
    public static function onLoadExtensionSchemaUpdates( \DatabaseUpdater $updater ): bool {
        $directory = $GLOBALS['wgExtensionDirectory'] . '/WSSpaces/sql';
        $type = $updater->getDB()->getType();

        // Associative array where the keys are the name of the table and the value the name of the associated SQL file
        $sql_files = [
            "wss_namespaces" => "wss_namespaces_table.sql",
            "wss_namespace_admins" => "wss_namespace_admins_table.sql"
        ];

        foreach ( $sql_files as $table => $sql_file ) {
            $path = sprintf( "%s/%s/%s", $directory, $type, $sql_file );

            if ( !file_exists( $path ) ) {
                throw new MWException( "WSSpaces does not support database type `$type`.` Please use `mysql`, `postgres` or `sqlite`." );
            }

            $updater->addExtensionTable( $table, $path );
        }

        return true;
    }

    /**
     * Called when generating the extensions credits, use this to change the tables headers.
     *
     * @param $extension_types
     * @return bool
     */
    public static function onExtensionTypes( array &$extension_types ): bool {
        $extension_types[ 'contentmanagement' ] = wfMessage( "version-contentmanagement" )->parse();
        return true;
    }
}