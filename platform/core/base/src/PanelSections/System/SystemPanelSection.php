<?php

namespace Botble\Base\PanelSections\System;

use Botble\Base\PanelSections\PanelSection;
use Botble\Base\PanelSections\PanelSectionItem;

class SystemPanelSection extends PanelSection
{
    public function setup(): void
    {
        $this
            ->setId('system')
            ->setTitle(trans('core/base::base.panel.system'))
            ->withPriority(99999)
            ->withItems([
                PanelSectionItem::make('cache_management')
                    ->setTitle(trans('core/base::cache.cache_management'))
                    ->withIcon('ti ti-box')
                    ->withDescription(trans('core/base::cache.cache_management_description'))
                    ->withPriority(1000)
                    ->withRoute('system.cache'),
                ! config('core.base.general.hide_cleanup_system_menu', false)
                    ? PanelSectionItem::make('system_cleanup')
                        ->setTitle(trans('core/base::system.cleanup.title'))
                        ->withIcon('ti ti-recycle')
                        ->withDescription(trans('core/base::system.cleanup.description'))
                        ->withPriority(2000)
                        ->withRoute('system.cleanup')
                    : null,
                PanelSectionItem::make('information')
                    ->setTitle(trans('core/base::system.info.title'))
                    ->withIcon('ti ti-info-circle')
                    ->withDescription(trans('core/base::system.info.description'))
                    ->withPriority(9990)
                    ->withRoute('system.info'),
            ]);
    }
}
