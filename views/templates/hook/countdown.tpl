{*
 * SCM Order Until — placeholder. Rendered server-side with NO API call.
 * views/js/countdown.js fetches the estimate client-side (browser -> API) and
 * fills [data-text] + [data-countdown], then ticks the countdown every second.
 * The state classes (scmou-open/scmou-closed) and [hidden] are toggled on the
 * wrapper ([data-scmou]); the footnote is static, rendered here per language.
 *}
<div class="scmou-wrap scmou-loading"
     data-scmou
     data-context="{$scmou_context|escape:'html':'UTF-8'}"
     data-cutoff="{$scmou_cutoff|escape:'html':'UTF-8'}"
     data-offset="{$scmou_offset|escape:'html':'UTF-8'}"
     data-locale="{$scmou_locale|escape:'html':'UTF-8'}"
     role="status" aria-live="polite" hidden>
  <div class="scmou-box">
    <span class="scmou-check" aria-hidden="true">
      <svg viewBox="0 0 20 20" width="18" height="18" focusable="false">
        <path d="M7.6 13.3 4.3 10l-1.1 1.1 4.4 4.4 9-9L15.5 5.4z" fill="currentColor"/>
      </svg>
    </span>
    <span class="scmou-lines">
      <span class="scmou-text" data-text></span>
    </span>
    <span class="scmou-badge" data-countdown hidden></span>
  </div>
  {if $scmou_footnote}
    <small class="scmou-note">{$scmou_footnote|escape:'html':'UTF-8'}</small>
  {/if}
</div>
