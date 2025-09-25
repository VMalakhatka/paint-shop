<?php /** @var \MediaCloud\Plugin\Wizard\Config\Field $field */?>
<div class="form-field field-checkbox">
    <div class="checkbox">
        <?php echo $__env->make('base/fields/checkbox', ['name' => $field->name(), 'value' => $field->defaultValue(), 'description' => '', 'conditions' => null], \MediaCloud\Vendor\Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?>
    </div>
    <div class="title">
        <?php echo e($field->title()); ?>

    </div>
    <div class="description">
        <?php echo $field->description(); ?>

    </div>
</div>
<?php /**PATH /Users/admin/Local Sites/paint/app/public/wp-content/plugins/ilab-media-tools/views/wizard/steps/form-fields/checkbox.blade.php ENDPATH**/ ?>