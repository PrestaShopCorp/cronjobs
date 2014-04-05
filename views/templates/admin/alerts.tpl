{if (isset($form_errors)) && (count($form_errors) > 0)}
	<div class="alert alert-danger">
		<h4>{l s='Error!' mod='psphipay'}</h4>
		<ul class="list-unstyled">
		{foreach from=$form_errors item='message'}
			<li>{$message}</li>
		{/foreach}
		</ul>
	</div>
{/if}

{if (isset($form_infos)) && (count($form_infos) > 0)}
	<div class="alert alert-warning">
		<h4>{l s='Notice!' mod='psphipay'}</h4>
		<ul class="list-unstyled">
		{foreach from=$form_infos item='message'}
			<li>{$message}</li>
		{/foreach}
		</ul>
	</div>
{/if}

{if (isset($form_successes)) && (count($form_successes) > 0)}
	<div class="alert alert-success">
		<h4>{l s='Success!' mod='psphipay'}</h4>
		<ul class="list-unstyled">
		{foreach from=$form_successes item='message'}
			<li>{$message}</li>
		{/foreach}
		</ul>
	</div>
{/if}
