(function(){
  "use strict";
  var notice = document.querySelector(".publishrank-rate-notice");
  if (!notice) return;
  var timer = null;
  var interval = (parseInt(notice.getAttribute("data-interval"), 10) || 300) * 1000;

  function schedule(ms){
    if (timer) window.clearTimeout(timer);
    timer = window.setTimeout(function(){ notice.hidden = false; }, ms);
  }
  function save(what){
    var body = new URLSearchParams();
    body.append("action", notice.getAttribute("data-action"));
    body.append("nonce", notice.getAttribute("data-nonce"));
    body.append("do", what);
    try {
      window.fetch(notice.getAttribute("data-ajax-url"), {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
        body: body.toString()
      });
    } catch (error) {}
  }

  // The notice was dismissed earlier: bring it back when the interval is over.
  var remaining = parseInt(notice.getAttribute("data-remaining"), 10) || 0;
  if (notice.hidden && remaining > 0) schedule(remaining * 1000);

  notice.addEventListener("click", function(event){
    var control = event.target && event.target.closest ? event.target.closest("[data-pr-rate]") : null;
    if (!control) return;
    var what = control.getAttribute("data-pr-rate");
    // The rating link keeps its normal behaviour and opens WordPress.org in a new tab.
    if (what === "dismiss") {
      notice.hidden = true;
      save("dismiss");
      schedule(interval);
    } else {
      notice.hidden = true;
      if (timer) window.clearTimeout(timer);
      save("rated");
    }
  });
})();
