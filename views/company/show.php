<?php
/**
 * The company detail screen body.
 *
 * Handed to `layout` as `$content` by `CompanyController::show()`, which loads the
 * record through the query service and passes the extracted
 * `CompanyDetailResponseDTO` as `$company`.
 *
 * The DTO is read through its properties, not array keys: it is a readonly object
 * the response mapper built, and reaching into it as an array would fail on the
 * first field. `$company->website` and its siblings are already formatted for the
 * wire by the mapper, so this template escapes and lays out - it decides nothing.
 *
 * The record is named by a query parameter (`?id=`), which is why the empty
 * placeholder is a real case here: a submission that carries no id is redirected
 * before this view renders, so the null branch below is for a company whose own
 * optional field is unset.
 *
 * @var \Core\Company\Application\DTOs\Responses\CompanyDetailResponseDTO $company
 */

/** @var \Core\Company\Application\DTOs\Responses\CompanyDetailResponseDTO $company */
?>

<h1 class="wp-heading-inline"><?= e($company->name) ?></h1>
<a href="<?= e(route('companies.index')) ?>" class="page-title-action">Back to companies</a>
<hr class="wp-header-end">

<table class="form-table" role="presentation">
    <tr>
        <th scope="row">Name</th>
        <td><strong><?= e($company->name) ?></strong></td>
    </tr>
    <tr>
        <th scope="row">Website</th>
        <td>
            <?php if ($company->website !== null): ?>
                <a href="<?= e($company->website) ?>"><?= e($company->website) ?></a>
            <?php else: ?>
                &mdash;
            <?php endif; ?>
        </td>
    </tr>
    <tr>
        <th scope="row">Industry</th>
        <td><?= $company->industry === null ? '&mdash;' : e($company->industry) ?></td>
    </tr>
    <tr>
        <th scope="row">Phone</th>
        <td><?= $company->phone === null ? '&mdash;' : e($company->phone) ?></td>
    </tr>
    <tr>
        <th scope="row">Address</th>
        <td><?php
            // `nl2br` after `e()`: the escaping has already neutralised the text,
            // so the only markup introduced is the line breaks this template adds
            // on purpose. The reverse order would let a stored `<br>` through.
            echo $company->address === null ? '&mdash;' : nl2br(e($company->address));
        ?></td>
    </tr>
    <tr>
        <th scope="row">Added</th>
        <td><?= $company->created_at === null ? '&mdash;' : e($company->created_at) ?></td>
    </tr>
</table>
