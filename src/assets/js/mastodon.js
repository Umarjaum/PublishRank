(function(){
  "use strict";
  function profileUrl(value){
    var text = String(value || "").trim();
    var handle = text.match(/^@?([a-z0-9_]+)@([a-z0-9.-]+\.[a-z]{2,})$/i);
    if (handle) return "https://" + handle[2].toLowerCase() + "/@" + handle[1];
    try {
      var url = new URL(text);
      return url.protocol === "https:" ? url.href : "";
    } catch (error) {
      return "";
    }
  }
  document.addEventListener("input", function(event){
    if (!event.target || event.target.id !== "pr-mastodon-profile") return;
    var field = document.getElementById("pr-mastodon-rel-code");
    if (!field) return;
    var url = profileUrl(event.target.value);
    field.value = url ? '<link rel="me" href="' + url.replace(/&/g, "&amp;") + '" />' : "";
  });
  document.addEventListener("click", function(event){
    var target = event.target;
    var button = target && target.closest ? target.closest("[data-pr-copy-target]") : null;
    if (!button) return;
    var input = document.getElementById(button.getAttribute("data-pr-copy-target"));
    var status = document.getElementById("pr-mastodon-copy-status");
    if (!input) return;
    var done = button.getAttribute("data-pr-copy-done") || "Copied";
    var failed = button.getAttribute("data-pr-copy-failed") || "Select and copy manually.";
    function announce(text){ if (status) status.textContent = text; }
    function fallback(){
      input.focus(); input.select(); input.setSelectionRange(0, input.value.length);
      try {
        if (document.execCommand("copy")) { announce(done); return; }
      } catch (error) {}
      announce(failed);
    }
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(input.value).then(function(){ announce(done); }, fallback);
    } else { fallback(); }
  });
})();
