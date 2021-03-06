<div class="section-title-wrapper">
	{{if $actionspanel}}
	<div class="pull-right">
		<a href="cloud_tiles/{{$cpath}}" class="btn btn-sm btn-outline-secondary"><i class="fa fa-fw {{if $tiles}}fa-list-ul{{else}}fa-table{{/if}}"></i></a>
		{{if $is_owner}}
		<a href="/sharedwithme" class="btn btn-sm btn-outline-secondary"><i class="fa fa-cloud-download"></i>&nbsp;{{$shared}}</a>
		{{/if}}
		<button id="files-create-btn" class="btn btn-sm btn-primary" onclick="openClose('files-mkdir-tools'); closeMenu('files-upload-tools');"><i class="fa fa-folder-o"></i>&nbsp;{{$create}}</button>
		<button id="files-upload-btn" class="btn btn-sm btn-success" onclick="openClose('files-upload-tools'); closeMenu('files-mkdir-tools');"><i class="fa fa-arrow-circle-o-up"></i>&nbsp;{{$upload}}</button>
	</div>
	{{/if}}
	<h2>{{$header}}</h2>
	<div class="clear"></div>
</div>
{{if $actionspanel}}
	{{$actionspanel}}
{{/if}}
