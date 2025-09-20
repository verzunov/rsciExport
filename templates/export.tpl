{**
 * plugins/importexport/rsciExport/templates/export.tpl
 *}
{extends file="layouts/backend.tpl"}

{block name="page"}
    <h1 class="app__pageHeading">{$pageTitle|escape}</h1>

    {if $exportSuccess}
        <div class="pkpNotification pkpNotification-success" role="alert" style="margin-bottom:1rem">
            {translate key="plugins.importexport.rsciExport.export.success"}
            — <a href="{$downloadUrl|escape}" class="pkpButton">
                {translate key="common.download"}
            </a>
        </div>
    {/if}

    <form method="post" action="{$exportByIssueUrl|escape}" class="app__contentPanel">
        {csrf}
        <p>{translate key="plugins.importexport.rsciExport.selectIssue"}</p>

        <table class="pkpTable">
            <thead>
            <tr>
                <th>{translate key="plugins.importexport.rsciExport.id"}</th>
                <th>{translate key="plugins.importexport.rsciExport.title"}</th>
                <th>{translate key="common.action"}</th>
            </tr>
            </thead>
            <tbody>
            {foreach from=$issues item=issue}
                <tr>
                    <td>{$issue->getId()|escape}</td>
                    <td>
                        {$issue->getLocalizedTitle()|escape}
                        {$issue->getNumber()|escape}
                        {$issue->getYear()|escape}
                    </td>
                    <td>
                        <input type="radio" name="issueId" value="{$issue->getId()|escape}" required>
                    </td>
                </tr>
            {/foreach}
            </tbody>
        </table>

        <button class="pkp_button" type="submit">
            {translate key="plugins.importexport.rsciExport.exportByIssue"}
        </button>
    </form>
{/block}
