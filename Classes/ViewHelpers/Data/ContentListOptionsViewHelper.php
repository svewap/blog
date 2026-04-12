<?php
declare(strict_types = 1);

/*
 * This file is part of the package t3g/blog.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace T3G\AgencyPack\Blog\ViewHelpers\Data;

use T3G\AgencyPack\Blog\Constants;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

class ContentListOptionsViewHelper extends AbstractViewHelper
{
    public function initializeArguments(): void
    {
        $this->registerArgument('as', 'string', 'Name of variable to create.');
        // @todo rename to type
        $this->registerArgument('listType', 'string', 'Plugin Type to Render', true);
    }

    public function render(): string
    {
        $arguments = $this->arguments;
        $settings = GeneralUtility::makeInstance(ConfigurationManagerInterface::class)
            ->getConfiguration(ConfigurationManagerInterface::CONFIGURATION_TYPE_SETTINGS, 'blog');
        $listTypeConfiguration = $settings['contentListOptions'][$arguments['listType']] ?? [];

        // Build a fake tt_content record with all system fields required by TYPO3 v14 RecordFactory.
        // Initialize all TCA columns with empty defaults to prevent IncompleteRecordException.
        $defaults = [];
        foreach (($GLOBALS['TCA']['tt_content']['columns'] ?? []) as $fieldName => $fieldConfig) {
            $defaults[$fieldName] = $fieldConfig['config']['default'] ?? '';
        }
        // System fields not in TCA columns but required by RecordFactory
        $defaults = array_merge($defaults, [
            'uid' => Constants::LISTTYPE_TO_FAKE_UID_MAPPING[$arguments['listType']] ?? 0,
            'pid' => 0,
            'crdate' => 0,
            'tstamp' => 0,
            'deleted' => 0,
            'hidden' => 0,
            'sorting' => 0,
            'sys_language_uid' => 0,
            'l18n_parent' => 0,
            'l10n_source' => 0,
            't3ver_oid' => 0,
            't3ver_wsid' => 0,
            't3ver_state' => 0,
            't3ver_stage' => 0,
            'starttime' => 0,
            'endtime' => 0,
            'fe_group' => '',
            'editlock' => 0,
            'rowDescription' => '',
            'colPos' => 0,
        ]);

        $data = array_merge(
            $defaults,
            $listTypeConfiguration,
            [
                'uid' => Constants::LISTTYPE_TO_FAKE_UID_MAPPING[$arguments['listType']] ?? 0,
                'CType' => $arguments['listType'] ?? '',
                'layout' => $listTypeConfiguration['layout'] ?? '0',
                'frame_class' => $listTypeConfiguration['frame_class'] ?? 'default',
            ]
        );

        $arguments['as'] = $arguments['as'] ?? 'contentObjectData';
        $variableProvider = $this->renderingContext->getVariableProvider();
        $variableProvider->remove($arguments['as']);
        $variableProvider->add($arguments['as'], $data);

        return '';
    }
}