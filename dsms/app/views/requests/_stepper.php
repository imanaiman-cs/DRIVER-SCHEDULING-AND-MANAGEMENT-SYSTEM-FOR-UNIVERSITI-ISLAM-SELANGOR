<?php /** @var int $step 1..4 */
$steps = ['Trip time', 'Choose vehicle', 'Details', 'Confirm'];
?>
<ol class="wizard-steps list-unstyled d-flex flex-wrap gap-2 mb-4">
    <?php foreach ($steps as $i => $label): $n = $i + 1; ?>
        <li class="wizard-step <?= $n === $step ? 'active' : ($n < $step ? 'done' : '') ?>">
            <span class="wizard-num"><?= $n ?></span> <?= e($label) ?>
        </li>
    <?php endforeach; ?>
</ol>
