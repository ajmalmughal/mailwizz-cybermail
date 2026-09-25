<?php declare(strict_types=1);
if (!defined('MW_PATH')) {
    exit('No direct script access allowed');
}

/**
 * Delivery server form for the CyberMail Email API.
 *
 * All field configuration lives in
 * DeliveryServerCybermailWebApi::getFormFieldsDefinition(), this view only
 * loops whatever the model declares.
 *
 * @copyright 2026 M. Ajmal Mughal
 * @license   MIT
 */

/** @var Controller $controller */
$controller = controller();

/** @var string $pageHeading */
$pageHeading = (string)$controller->getData('pageHeading');

/** @var DeliveryServerCybermailWebApi $server */
$server = $controller->getData('server');

/** @var bool $canSelectTrackingDomains */
$canSelectTrackingDomains = (bool)$controller->getData('canSelectTrackingDomains');

/**
 * This hook gives a chance to prepend content or to replace the default view content with a custom content.
 * @since 1.3.3.1
 */
hooks()->doAction('before_view_file_content', $viewCollection = new CAttributeCollection([
    'controller'    => $controller,
    'renderContent' => true,
]));

// and render if allowed
if ($viewCollection->itemAt('renderContent')) {
    $controller->renderPartial('_confirm-form');

    /**
     * This hook gives a chance to prepend content before the active form or to replace the default active form entirely.
     * @since 1.3.3.1
     */
    hooks()->doAction('before_active_form', $collection = new CAttributeCollection([
        'controller' => $controller,
        'renderForm' => true,
    ]));

    // and render if allowed
    if ($collection->itemAt('renderForm')) {
        /** @var CActiveForm $form */
        $form = $controller->beginWidget('CActiveForm'); ?>
        <div class="box box-primary borderless">
            <div class="box-header">
                <div class="pull-left">
                    <?php BoxHeaderContent::make(BoxHeaderContent::LEFT)
                        ->add('<h3 class="box-title">' . IconHelper::make('glyphicon-send') . html_encode((string)$pageHeading) . '</h3>')
                        ->render(); ?>
                </div>
                <div class="pull-right">
                    <?php BoxHeaderContent::make(BoxHeaderContent::RIGHT)
                        ->add(CHtml::link(IconHelper::make('cancel') . t('app', 'Cancel'), ['delivery_servers/index'], ['class' => 'btn btn-primary btn-flat', 'title' => t('app', 'Cancel')]))
                        ->addIf(CHtml::link(IconHelper::make('info'), '#page-info', ['class' => 'btn btn-primary btn-flat', 'title' => t('app', 'Info'), 'data-toggle' => 'modal']), !$server->getIsNewRecord())
                        ->render(); ?>
                </div>
                <div class="clearfix"><!-- --></div>
            </div>
            <div class="box-body">
                <?php
                /**
                 * This hook gives a chance to prepend content before the active form fields.
                 * @since 1.3.3.1
                 */
                hooks()->doAction('before_active_form_fields', new CAttributeCollection([
                    'controller' => $controller,
                    'form'       => $form,
                ])); ?>

                <div class="callout callout-info">
                    <h4><?php echo t('servers', 'Webhook setup'); ?></h4>
                    <p>
                        <?php echo t('servers', 'Bounces and complaints are recorded from CyberMail webhooks. Create a webhook in your CyberMail dashboard, leave all events selected, then paste the signing secret it shows you into the field above. The secret is displayed once only.'); ?>
                    </p>
                    <?php if (!$server->getIsNewRecord()) { ?>
                    <p>
                        <?php echo t('servers', 'Point the webhook at:'); ?>
                        <br /><strong><?php echo html_encode((string)$server->getDswhUrl()); ?></strong>
                    </p>
                    <?php } else { ?>
                    <p>
                        <?php echo t('servers', 'Save this server first, then reopen it to see the webhook url to register.'); ?>
                    </p>
                    <?php } ?>
                </div>

                <div class="row">
                    <?php
                    $index      = 0;
                    $formFields = $server->getFormFieldsDefinition([
                        'tracking_domain_id' => [
                            'visible' => !empty($canSelectTrackingDomains),
                        ],
                    ]);

                    foreach ($formFields as $fieldName => $fieldProps) {
                        $index++; ?>
                        <div class="col-lg-3">
                            <div class="form-group">
                                <?php echo $form->labelEx($server, $fieldName); ?>
                                <?php echo $fieldProps['fieldHtml']; ?>
                                <?php echo $form->error($server, $fieldName); ?>
                            </div>
                        </div>
                        <?php if ($index % 4 === 0) { ?></div><div class="row"><?php } ?>
                    <?php } ?>
                </div>

                <?php
                /**
                 * This hook gives a chance to append content after the active form fields.
                 * @since 1.3.3.1
                 */
                hooks()->doAction('after_active_form_fields', new CAttributeCollection([
                    'controller' => $controller,
                    'form'       => $form,
                ])); ?>

                <?php $controller->renderPartial('_after-form-fields', compact('form')); ?>

            </div>
            <div class="box-footer">
                <div class="pull-right">
                    <button type="submit" class="btn btn-primary btn-flat"><?php echo IconHelper::make('save') . t('app', 'Save changes'); ?></button>
                </div>
                <div class="clearfix"><!-- --></div>
            </div>
        </div>

        <?php if (!$server->getIsNewRecord()) { ?>
        <!-- modals -->
        <div class="modal modal-info fade" id="page-info" tabindex="-1" role="dialog">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
                        <h4 class="modal-title"><?php echo IconHelper::make('info') . t('app', 'Info'); ?></h4>
                    </div>
                    <div class="modal-body">
                        <?php echo t('servers', 'The url where this server expects to receive webhooks requests is: {url}.', ['{url}' => sprintf('<strong>%s</strong>', $server->getDswhUrl())]); ?><br />
                    </div>
                </div>
            </div>
        </div>
        <?php } ?>

        <?php
        $controller->endWidget();
    }

    /**
     * This hook gives a chance to append content after the active form.
     * @since 1.3.3.1
     */
    hooks()->doAction('after_active_form', new CAttributeCollection([
        'controller'   => $controller,
        'renderedForm' => $collection->itemAt('renderForm'),
    ]));
}

/**
 * This hook gives a chance to append content after the view file default content.
 * @since 1.3.3.1
 */
hooks()->doAction('after_view_file_content', new CAttributeCollection([
    'controller'      => $controller,
    'renderedContent' => $viewCollection->itemAt('renderContent'),
]));
