
{**
 * templates/export.tpl
 *
 * Copyright (c) 2014-2020 Simon Fraser University
 * Copyright (c) 2003-2020 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @brief UI to export published submissions
 *}
{extends file="layouts/backend.tpl"}

{block name="page"}
	<h1 class="app__pageHeading">
		{$pageTitle}
	</h1>
	<form method="POST" action="{plugin_url path="exportByIssue"}">
		<p>{translate key="plugins.importexport.rsciExport.selectIssue"}</p>
	<div class="app__contentPanel">
		<table class="pkpTable">
			<thead>
			<tr>
				<th>{translate key="plugins.importexport.rsciExport.id"}</th>
				<th>{translate key="plugins.importexport.rsciExport.title"}</th>
			</tr>
			</thead>
			<tbody>
			{foreach $issues as $issue}
				<tr>
					<td>{$issue->getId()}</td>
					<td>{$issue->getLocalizedTitle()} {$issue->getNumber()} {$issue->getYear()}</td>
					<td><input type="radio" id="issue{$issue->getId()}" name="issueId" value="{$issue->getId()}" required></td>
				</tr>
			{/foreach}
			</tbody>
		</table>


			<button class="pkp_button" type="submit">{translate key="plugins.importexport.rsciExport.exportByIssue"}</button>
	</form>
	</div>
{/block}
