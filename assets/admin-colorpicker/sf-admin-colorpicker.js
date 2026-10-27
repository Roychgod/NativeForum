(function(){
  "use strict";

  function clamp(n,min,max){return Math.max(min, Math.min(max,n));}

  function hex2(n){
    var s = n.toString(16);
    return s.length===1 ? "0"+s : s;
  }

  function parseToRgba(val){
    if(!val) return {r:0,g:0,b:0,a:0};
    var v = String(val).trim();
    // #RGB, #RRGGBB, #RRGGBBAA
    if(v[0]==="#"){
      var h = v.slice(1);
      if(h.length===3){
        var r = parseInt(h[0]+h[0],16), g=parseInt(h[1]+h[1],16), b=parseInt(h[2]+h[2],16);
        return {r:r,g:g,b:b,a:1};
      }
      if(h.length===6 || h.length===8){
        var r2=parseInt(h.slice(0,2),16), g2=parseInt(h.slice(2,4),16), b2=parseInt(h.slice(4,6),16);
        var a2=1;
        if(h.length===8) a2 = parseInt(h.slice(6,8),16)/255;
        return {r:r2,g:g2,b:b2,a:a2};
      }
    }
    // rgba(r,g,b,a)
    var m = v.match(/rgba?\(([^)]+)\)/i);
    if(m){
      var parts = m[1].split(/\s*,\s*/).map(function(x){return x.trim();});
      var r3 = clamp(parseInt(parts[0]||"0",10)||0,0,255);
      var g3 = clamp(parseInt(parts[1]||"0",10)||0,0,255);
      var b3 = clamp(parseInt(parts[2]||"0",10)||0,0,255);
      var a3 = 1;
      if(parts.length>3){
        a3 = clamp(parseFloat(parts[3])||0,0,1);
      }
      return {r:r3,g:g3,b:b3,a:a3};
    }
    return {r:0,g:0,b:0,a:0};
  }

  function rgbaToHex8(r,g,b,a){
    var ai = clamp(Math.round(a*255),0,255);
    return "#" + hex2(clamp(r,0,255)) + hex2(clamp(g,0,255)) + hex2(clamp(b,0,255)) + hex2(ai);
  }

  function installForInput(input){
    if(input.dataset.sfcpInit) return;
    input.dataset.sfcpInit = "1";

    // Wrap (swatch + button)
    var wrap = document.createElement("div");
    wrap.className = "sfcp-wrap";
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);

    var swatch = document.createElement("span");
    swatch.className = "sfcp-swatch";
    wrap.insertBefore(swatch, input);

    // Keep a trigger element for anchoring/keyboard, but use the swatch as the primary opener.
    var btn = document.createElement("button");
    btn.type = "button";
    btn.className = "sfcp-trigger";
    btn.textContent = "Pick";
    wrap.appendChild(btn);

    function refreshSwatch(){
      var rgba = parseToRgba(input.value);
      var color = rgbaToHex8(rgba.r, rgba.g, rgba.b, rgba.a);
      swatch.style.setProperty("--sfcp-color", input.value ? color : "transparent");
    }
    refreshSwatch();

    var panel = null;
    var onScroll = null;
    var onResize = null;

    function closePanel(){
      if(panel){
        panel.remove();
        panel = null;
      }
      if(onScroll){ window.removeEventListener('scroll', onScroll, true); onScroll = null; }
      if(onResize){ window.removeEventListener('resize', onResize, true); onResize = null; }
    }

    function positionPanel(){
      if(!panel) return;
      var anchor = swatch.getBoundingClientRect();
      // Measure panel (it is already in DOM when this runs)
      var pw = panel.offsetWidth || 260;
      var ph = panel.offsetHeight || 200;
      var margin = 10;

      // Default: below swatch, left aligned
      var left = anchor.left;
      var top = anchor.bottom + 8;

      // Keep inside viewport horizontally
      if(left + pw + margin > window.innerWidth) left = window.innerWidth - pw - margin;
      if(left < margin) left = margin;

      // If near bottom, open above
      if(top + ph + margin > window.innerHeight) {
        top = anchor.top - ph - 8;
      }
      if(top < margin) top = margin;

      panel.style.left = left + "px";
      panel.style.top = top + "px";
    }

    function openPanel(){
      closePanel();
      panel = document.createElement("div");
      panel.className = "sfcp-panel";

      var rgba = parseToRgba(input.value);
      var colorInput = document.createElement("input");
      colorInput.type = "color";
      colorInput.value = "#" + hex2(rgba.r) + hex2(rgba.g) + hex2(rgba.b);

      var alpha = document.createElement("input");
      alpha.type = "range";
      alpha.min = "0";
      alpha.max = "100";
      alpha.step = "1";
      alpha.value = String(Math.round((isFinite(rgba.a)?rgba.a:1)*100));

      var hexBox = document.createElement("span");
      hexBox.className = "sfcp-hex";

      function updateFromControls(){
        var c = colorInput.value || "#000000";
        var rr = parseInt(c.slice(1,3),16)||0;
        var gg = parseInt(c.slice(3,5),16)||0;
        var bb = parseInt(c.slice(5,7),16)||0;
        var aa = clamp(parseInt(alpha.value,10)/100,0,1);
        var hex8 = rgbaToHex8(rr,gg,bb,aa).toUpperCase();
        input.value = hex8;
        hexBox.textContent = hex8;
        refreshSwatch();
      }

      function updateHexBoxOnly(){
        var rr = parseInt((colorInput.value||"#000000").slice(1,3),16)||0;
        var gg = parseInt((colorInput.value||"#000000").slice(3,5),16)||0;
        var bb = parseInt((colorInput.value||"#000000").slice(5,7),16)||0;
        var aa = clamp(parseInt(alpha.value,10)/100,0,1);
        var hex8 = rgbaToHex8(rr,gg,bb,aa).toUpperCase();
        hexBox.textContent = hex8;
      }

      // Rows
      var row1 = document.createElement("div"); row1.className = "sfcp-row";
      row1.innerHTML = "<label>Color</label>";
      row1.appendChild(colorInput);

      var row2 = document.createElement("div"); row2.className = "sfcp-row";
      row2.innerHTML = "<label>Alpha</label>";
      row2.appendChild(alpha);

      var row3 = document.createElement("div"); row3.className = "sfcp-row";
      row3.innerHTML = "<label>Hex</label>";
      row3.appendChild(hexBox);

      var actions = document.createElement("div"); actions.className = "sfcp-actions";
      var clear = document.createElement("button"); clear.type = "button"; clear.className = "sfcp-btn"; clear.textContent = "Clear";
      var done = document.createElement("button"); done.type = "button"; done.className = "sfcp-btn"; done.textContent = "Done";
      actions.appendChild(clear); actions.appendChild(done);

      panel.appendChild(row1);
      panel.appendChild(row2);
      panel.appendChild(row3);
      panel.appendChild(actions);

      // Initial hex
      updateHexBoxOnly();

      colorInput.addEventListener("input", updateFromControls);
      alpha.addEventListener("input", updateFromControls);
      clear.addEventListener("click", function(){ input.value = ""; refreshSwatch(); closePanel(); });
      done.addEventListener("click", function(){ updateFromControls(); closePanel(); });

      document.body.appendChild(panel);
      // Position (fixed) and keep it anchored while scrolling.
      positionPanel();

      onScroll = function(){ positionPanel(); };
      onResize = function(){ positionPanel(); };
      window.addEventListener('scroll', onScroll, true);
      window.addEventListener('resize', onResize, true);

      // Close on outside click
      setTimeout(function(){
        function onDoc(e){
          if(!panel) return;
          if(panel.contains(e.target) || wrap.contains(e.target)) return;
          document.removeEventListener("mousedown", onDoc, true);
          closePanel();
        }
        document.addEventListener("mousedown", onDoc, true);
      }, 0);
    }

    // Open by clicking the swatch (preferred UX)
    swatch.addEventListener("click", function(e){
      e.preventDefault();
      openPanel();
    });
    // Also allow keyboard trigger on hidden button if focused via tab.
    btn.addEventListener("click", openPanel);
    input.addEventListener("change", refreshSwatch);
    input.addEventListener("input", refreshSwatch);
  }

  function init(){
    var inputs = document.querySelectorAll('input.sf-colorpicker');
    inputs.forEach(installForInput);
  }

  if(document.readyState === "loading") document.addEventListener("DOMContentLoaded", init);
  else init();
})();
