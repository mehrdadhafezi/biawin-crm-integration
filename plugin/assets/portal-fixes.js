(function(){
  "use strict";
  function shell(){return document.querySelector(".bcrm-portal-shell");}
  function syncButton(el){
    var btn=document.querySelector("[data-bcrm-theme-toggle]");
    if(btn) btn.textContent=el&&el.classList.contains("is-dark")?"☀":"☾";
  }
  function apply(){
    var el=shell();
    if(!el) return;
    try{if(localStorage.getItem("bcrm_theme")==="dark") el.classList.add("is-dark");}catch(e){}
    try{
      var savedFont=localStorage.getItem("bcrm_font");
      var select=document.querySelector("[data-bcrm-font-switch]");
      if(savedFont&&select){
        var option=select.querySelector('option[value="'+savedFont.replace(/"/g,'')+'"]');
        if(option){select.value=savedFont; el.style.setProperty("--bcrm-font-family", option.getAttribute("data-font-stack")||"Tahoma, Arial, sans-serif");}
      }
    }catch(e){}
    syncButton(el);
  }
  document.addEventListener("DOMContentLoaded",apply);
  document.addEventListener("click",function(e){
    var btn=e.target.closest("[data-bcrm-theme-toggle]");
    if(!btn) return;
    e.preventDefault();
    e.stopImmediatePropagation();
    var el=shell();
    if(!el) return;
    el.classList.toggle("is-dark");
    try{localStorage.setItem("bcrm_theme",el.classList.contains("is-dark")?"dark":"light");}catch(err){}
    syncButton(el);
  }, true);
  document.addEventListener("change",function(e){
    var select=e.target.closest("[data-bcrm-font-switch]");
    if(!select) return;
    var el=shell();
    var option=select.options[select.selectedIndex];
    if(el&&option) el.style.setProperty("--bcrm-font-family", option.getAttribute("data-font-stack")||"Tahoma, Arial, sans-serif");
    try{localStorage.setItem("bcrm_font",select.value||"system");}catch(err){}
  });
  /* =====================================================================
     v0.8.62.27 — آپلود فیش (تنها پیاده‌سازی؛ frontend.js دیگر نسخه تکراری ندارد).
     - هر فایل همان لحظه انتخاب، جداگانه آپلود می‌شود (۳ همزمان) و وضعیتش کنار ردیف دیده می‌شود.
     - انتخاب چند فایل با هم → برای هر فایل یک ردیف با مبلغ جدا.
     - هر فرمتی؛ عکس‌های حجیم (JPEG/PNG/WebP بالای ۲٫۵MB) پیش از ارسال کم‌حجم می‌شوند.
     - قطع اینترنت موبایل: تلاش خودکار با فاصله فزاینده؛ nonce منقضی: گرفتن تنظیمات تازه.
     - فیش‌های آپلودشده روی سرور (صف اختصاصی کاربر+پرونده) با رفرش/خطا از دست نمی‌روند
       و هنگام باز شدن دوباره فرم بازیابی می‌شوند.
     - ثبت نهایی فقط شناسه‌ها + مبلغ‌ها را می‌فرستد و قبلش وجود همه فیش‌ها را روی سرور
       چک می‌کند؛ فیش گم‌شده خودکار دوباره آپلود می‌شود.
     ===================================================================== */
  function initReceiptUploader(form){
    if(form.dataset.bcrmUploaderReady==="1") return;
    var list=form.querySelector("[data-bcrm-receipts]");
    var firstRow=list&&list.querySelector(".bcrm-receipt-row");
    if(!list||!firstRow) return;
    form.dataset.bcrmUploaderReady="1";
    form.dataset.receiptsReady="1"; /* کش قدیمی frontend.js نسخه‌های قبل را هم از این فرم دور نگه می‌دارد */
    var caseField=form.querySelector('input[name="case_id"]');
    var caseId=caseField?String(caseField.value||"0"):"0";
    var statusBox=form.querySelector("[data-bcrm-stage-status]");
    var noticeBox=document.createElement("p");
    noticeBox.className="bcrm-stage-notice";
    noticeBox.hidden=true;
    if(statusBox&&statusBox.parentNode) statusBox.parentNode.insertBefore(noticeBox,statusBox.nextSibling);
    var template=firstRow.cloneNode(true);
    template.querySelectorAll("input").forEach(function(i){ i.value=""; });
    var PARALLEL=3,MAX_TRIES=6,SHRINK_OVER=2.5*1048576,SHRINK_MAX_SIDE=2560;
    var queue=[],active=0,cfg=window.BCRM_STAGE_CFG||null,maxBytes=0,submitting=false;
    var AMOUNT_KEY="bcrm_receipt_amounts_"+caseId;

    function toLatin(v){ return String(v||"").replace(/[۰-۹]/g,function(d){return "۰۱۲۳۴۵۶۷۸۹".indexOf(d);}).replace(/[٠-٩]/g,function(d){return "٠١٢٣٤٥٦٧٨٩".indexOf(d);}); }
    function fmtSize(b){ b=+b||0; return b>=1048576?(b/1048576).toFixed(1)+" MB":Math.max(1,Math.round(b/1024))+" KB"; }
    function rows(){ return Array.prototype.slice.call(list.querySelectorAll(".bcrm-receipt-row")); }
    function amountInput(row){ return row.querySelector('input[name="receipt_amounts[]"]'); }
    function hasReceipt(row){ return !!(row.dataset.stagedId||row._bcrmFile); }
    function notice(text){ noticeBox.textContent=text||""; noticeBox.hidden=!text; }
    function statusEl(row){
      var s=row.querySelector("[data-bcrm-row-status]");
      if(!s){
        s=document.createElement("span");
        s.className="bcrm-receipt-row-status";
        s.setAttribute("data-bcrm-row-status","");
        row.insertBefore(s,row.querySelector("[data-bcrm-remove-receipt]"));
      }
      return s;
    }
    function setRow(row,state,text){
      row.dataset.bcrmState=state||"";
      ["queued","uploading","done","error"].forEach(function(k){ row.classList.toggle("is-"+k,k===state); });
      var s=statusEl(row);
      s.textContent=text||"";
      if(state==="error"&&row._bcrmFile){
        var b=document.createElement("button");
        b.type="button"; b.className="button bcrm-receipt-retry"; b.setAttribute("data-bcrm-retry-receipt",""); b.textContent="تلاش دوباره";
        s.appendChild(document.createTextNode(" ")); s.appendChild(b);
      }
      summary();
    }
    function summary(){
      if(!statusBox) return;
      var t=0,d=0,u=0,e=0;
      rows().forEach(function(r){ var s=r.dataset.bcrmState; if(!s) return; t++; if(s==="done") d++; else if(s==="error") e++; else u++; });
      statusBox.textContent=t?("فیش‌ها: "+d+" از "+t+" آپلود شد"+(u?" · در حال آپلود: "+u:"")+(e?" · ناموفق: "+e+" (دکمه «تلاش دوباره» کنار ردیف)":"")):"";
      statusBox.classList.toggle("is-error",e>0);
    }
    function localCfg(){
      var A=window.BCRM_ADMIN||{},B=window.BiawinCRM||{};
      return {url:A.ajaxUrl||B.ajaxUrl||"",nonce:A.stageNonce||B.stageNonce||"",token:A.stagingToken||B.stagingToken||""};
    }
    function refreshCfg(cb){
      var c=localCfg();
      if(!c.url){ cb(false); return; }
      var fd=new FormData(); fd.append("action","bcrm_stage_config");
      var xhr=new XMLHttpRequest();
      xhr.open("POST",c.url,true);
      xhr.onreadystatechange=function(){
        if(xhr.readyState!==4) return;
        var j=null; try{ j=JSON.parse(xhr.responseText); }catch(err){}
        if(xhr.status===200&&j&&j.ok&&j.nonce&&j.token){
          cfg={url:c.url,nonce:j.nonce,token:j.token}; window.BCRM_STAGE_CFG=cfg;
          if(j.maxFileBytes) maxBytes=+j.maxFileBytes;
          cb(true); return;
        }
        cb(false);
      };
      xhr.send(fd);
    }
    /* cb(status, json, text) — status 0 = شبکه/قطع اتصال */
    function post(action,data,file,onProgress,cb){
      var c=cfg||localCfg();
      if(!c.url){ cb(-1,null,"آدرس سرور آپلود در صفحه نیست؛ صفحه را دوباره بارگذاری کنید."); return; }
      var fd=new FormData();
      fd.append("action",action); fd.append("nonce",c.nonce||""); fd.append("staging_token",c.token||""); fd.append("case_id",caseId);
      for(var k in data){ if(Object.prototype.hasOwnProperty.call(data,k)) fd.append(k,data[k]); }
      if(file) fd.append("case_documents",file,file.name||"receipt");
      var xhr=new XMLHttpRequest();
      xhr.open("POST",c.url,true);
      if(onProgress&&xhr.upload) xhr.upload.onprogress=function(ev){ if(ev.lengthComputable&&ev.total) onProgress(ev.loaded/ev.total); };
      xhr.onreadystatechange=function(){
        if(xhr.readyState!==4) return;
        var j=null; try{ j=JSON.parse(xhr.responseText); }catch(err){}
        cb(xhr.status,j,String(xhr.responseText||""));
      };
      xhr.send(fd);
    }
    /* عکس حجیم موبایل → JPEG با ضلع حداکثر ۲۵۶۰px؛ هر خطا = همان فایل اصلی */
    function maybeShrink(file,cb){
      try{
        var canvasOk=!!(document.createElement("canvas").toBlob);
        if(!/^image\/(jpe?g|png|webp)$/i.test(file.type||"")||file.size<SHRINK_OVER||!window.URL||!canvasOk){ cb(file); return; }
        var url=URL.createObjectURL(file),img=new Image(),finished=false;
        var finish=function(f){ if(finished) return; finished=true; try{ URL.revokeObjectURL(url); }catch(err){} cb(f); };
        var timer=setTimeout(function(){ finish(file); },20000);
        img.onerror=function(){ clearTimeout(timer); finish(file); };
        img.onload=function(){
          clearTimeout(timer);
          try{
            var w=img.naturalWidth,h=img.naturalHeight;
            if(!w||!h){ finish(file); return; }
            var scale=Math.min(1,SHRINK_MAX_SIDE/Math.max(w,h)),cw=Math.round(w*scale),ch=Math.round(h*scale);
            var canvas=document.createElement("canvas"); canvas.width=cw; canvas.height=ch;
            var ctx=canvas.getContext("2d"); ctx.fillStyle="#fff"; ctx.fillRect(0,0,cw,ch); ctx.drawImage(img,0,0,cw,ch);
            canvas.toBlob(function(blob){
              if(!blob||blob.size>=file.size*0.9){ finish(file); return; }
              var name=(file.name||"receipt").replace(/\.[^.]+$/,"")+".jpg",out;
              try{ out=new File([blob],name,{type:"image/jpeg",lastModified:Date.now()}); }catch(err){ out=blob; out.name=name; }
              finish(out);
            },"image/jpeg",0.85);
          }catch(err){ finish(file); }
        };
        img.src=url;
      }catch(err){ cb(file); }
    }
    function releaseRemote(id,exceptRow){
      if(!id) return;
      var used=rows().some(function(r){ return r!==exceptRow&&r.dataset.stagedId===id; });
      if(!used) post("bcrm_stage_receipt_remove",{staged_id:id},null,null,function(){});
    }
    function enqueue(row){
      if(!row._bcrmFile) return;
      row._bcrmTries=0; row._bcrmRefreshed=false;
      setRow(row,"queued","در صف آپلود…");
      if(queue.indexOf(row)<0) queue.push(row);
      pump();
    }
    function pump(){
      while(active<PARALLEL&&queue.length){
        var row=queue.shift();
        if(!row.isConnected||!row._bcrmFile||row.dataset.bcrmState!=="queued") continue;
        active++;
        upload(row,function(){ active--; pump(); });
      }
    }
    function errorText(status,j,text,name){
      if(j&&j.message) return j.message;
      if(status===413) return "حجم فایل از سقف مجاز سرور بیشتر است.";
      var detail=text.replace(/<[^>]*>/g," ").replace(/\s+/g," ").trim().slice(0,160);
      var waf=(status===403)?" — احتمالاً فایروال هاست (mod_security) درخواست را بسته است؛ این پیام را به پشتیبانی هاست نشان دهید.":"";
      return "آپلود «"+name+"» ناموفق بود (کد "+status+")"+(detail?": "+detail:"")+waf;
    }
    function upload(row,done){
      var file=row._bcrmFile,token=row._bcrmToken=(row._bcrmToken||0)+1;
      var alive=function(){ return row._bcrmToken===token&&row.isConnected; };
      setRow(row,"uploading","در حال آماده‌سازی…");
      maybeShrink(file,function(f){
        if(!alive()){ done(); return; }
        if(maxBytes&&f.size>maxBytes){ setRow(row,"error","حجم فایل ("+fmtSize(f.size)+") از سقف "+fmtSize(maxBytes)+" بیشتر است."); done(); return; }
        var attempt=function(){
          row._bcrmTries++;
          post("bcrm_stage_receipt",{field:"case_documents"},f,function(p){
            if(alive()) setRow(row,"uploading","در حال آپلود "+Math.round(p*100)+"٪ — "+fmtSize(f.size));
          },function(status,j,text){
            if(!alive()){
              if(status===200&&j&&j.ok) releaseRemote(j.id,null); /* ردیف حذف/جایگزین شد؛ فایل یتیم روی سرور نماند */
              done(); return;
            }
            if(status===200&&j&&j.ok&&j.id){
              row.dataset.stagedId=j.id;
              setRow(row,"done","✓ آپلود شد — "+(j.name||f.name)+" ("+fmtSize(j.size||f.size)+")");
              saveAmount(row);
              done(); return;
            }
            var authy=status===401||status===403&&!!j||!!(j&&(j.code==="auth"||j.code==="token"));
            if(authy&&!row._bcrmRefreshed){ row._bcrmRefreshed=true; refreshCfg(function(){ attempt(); }); return; }
            var retryable=status===0||status>=500||status===408||status===429||(status===400&&!!j&&(j.code==="db"||j.code==="no_payload"||j.code==="unreadable"));
            if(retryable&&row._bcrmTries<MAX_TRIES){
              var wait=Math.min(15000,1000*Math.pow(2,row._bcrmTries-1));
              setRow(row,"uploading",(status===0?"اتصال قطع شد":"خطای موقت سرور")+"؛ تلاش دوباره ("+row._bcrmTries+" از "+(MAX_TRIES-1)+")…");
              setTimeout(function(){ if(alive()) attempt(); else done(); },wait);
              return;
            }
            setRow(row,"error",status===0?"ارتباط با سرور برقرار نشد؛ اینترنت را بررسی و «تلاش دوباره» را بزنید.":errorText(status,j,text,f.name||"فیش"));
            done();
          });
        };
        attempt();
      });
    }
    function loadAmounts(){ try{ return JSON.parse(localStorage.getItem(AMOUNT_KEY)||"{}")||{}; }catch(err){ return {}; } }
    function saveAmount(row){
      var id=row.dataset.stagedId,a=amountInput(row);
      if(!id||!a) return;
      try{ var m=loadAmounts(); m[id]=a.value; localStorage.setItem(AMOUNT_KEY,JSON.stringify(m)); }catch(err){}
    }
    function newRow(){ return template.cloneNode(true); }
    function assign(row,file){
      var old=row.dataset.stagedId;
      if(old){ delete row.dataset.stagedId; releaseRemote(old,row); }
      row._bcrmFile=file; row._bcrmRestored=false;
      enqueue(row);
    }
    function resetRow(row){
      row._bcrmToken=(row._bcrmToken||0)+1;
      var old=row.dataset.stagedId;
      delete row.dataset.stagedId; row._bcrmFile=null;
      if(old) releaseRemote(old,row);
      row.querySelectorAll("input").forEach(function(i){ i.value=""; });
      setRow(row,"","");
    }
    /* بازیابی فیش‌هایی که قبلاً برای همین پرونده آپلود شده ولی ثبت نهایی نشده‌اند */
    function restore(){
      post("bcrm_stage_list",{},null,null,function(status,j){
        if(status!==200||!j||!j.ok||!j.items||!j.items.length) return;
        var have={},amounts=loadAmounts(),n=0;
        rows().forEach(function(r){ if(r.dataset.stagedId) have[r.dataset.stagedId]=1; });
        j.items.forEach(function(it){
          if(have[it.id]) return;
          var target=null;
          rows().forEach(function(r){ var a=amountInput(r); if(!target&&!hasReceipt(r)&&!(a&&a.value)) target=r; });
          if(!target){ target=newRow(); list.appendChild(target); }
          target.dataset.stagedId=it.id; target._bcrmRestored=true;
          var a=amountInput(target); if(a&&amounts[it.id]) a.value=amounts[it.id];
          setRow(target,"done","✓ از قبل آپلود شده — "+it.name+" ("+fmtSize(it.size)+")");
          n++;
        });
        if(n) notice(n+" فیش که قبلاً برای این پرونده آپلود کرده بودید و هنوز ثبت نهایی نشده بود بازیابی شد؛ مبلغ‌ها را کنترل کنید (فیش اضافه را با «حذف» بردارید).");
      });
    }

    form.addEventListener("change",function(e){
      var inp=e.target;
      if(!inp||inp.type!=="file"||!list.contains(inp)) return;
      var row=inp.closest(".bcrm-receipt-row");
      var files=Array.prototype.slice.call(inp.files||[]);
      try{ inp.value=""; }catch(err){} /* فایل در حافظه ردیف می‌ماند؛ فرم نهایی هرگز خود فایل‌ها را دوباره نمی‌فرستد */
      if(!row||!files.length) return;
      notice("");
      assign(row,files[0]);
      var after=row;
      for(var i=1;i<files.length;i++){
        var r=newRow();
        after.parentNode.insertBefore(r,after.nextSibling);
        after=r;
        assign(r,files[i]);
      }
      if(files.length>1) notice(files.length+" فایل انتخاب شد و برای هر کدام یک ردیف ساخته شد؛ مبلغ هر فیش را وارد کنید.");
    });
    form.addEventListener("input",function(e){
      var row=e.target&&e.target.closest?e.target.closest(".bcrm-receipt-row"):null;
      if(row&&e.target.name==="receipt_amounts[]") saveAmount(row);
    });
    form.addEventListener("click",function(e){
      var add=e.target.closest("[data-bcrm-add-receipt]");
      var remove=e.target.closest("[data-bcrm-remove-receipt]");
      var retry=e.target.closest("[data-bcrm-retry-receipt]");
      if(add){
        e.preventDefault();
        var r=newRow(); list.appendChild(r);
        var a=amountInput(r); if(a) a.focus();
      }
      if(remove){
        e.preventDefault();
        var row=remove.closest(".bcrm-receipt-row");
        if(!row) return;
        var qi=queue.indexOf(row); if(qi>-1) queue.splice(qi,1);
        if(rows().length>1){
          row._bcrmToken=(row._bcrmToken||0)+1;
          var old=row.dataset.stagedId; delete row.dataset.stagedId; row._bcrmFile=null;
          row.remove();
          if(old) releaseRemote(old,null);
          summary();
        } else {
          resetRow(row);
        }
      }
      if(retry){
        e.preventDefault();
        var rr=retry.closest(".bcrm-receipt-row");
        if(rr) enqueue(rr);
      }
    });
    window.addEventListener("beforeunload",function(e){
      if(submitting) return;
      var busy=rows().some(function(r){ return r.dataset.bcrmState==="queued"||r.dataset.bcrmState==="uploading"; });
      if(busy){ e.preventDefault(); e.returnValue="آپلود فیش‌ها هنوز تمام نشده است."; return e.returnValue; }
    });

    function submitButton(){ return form.querySelector('button[type="submit"],button.button-primary,button:not([type])'); }
    function waitAll(rowsToWait,cb){
      var tick=function(){
        var busy=rowsToWait.some(function(r){ return r.isConnected&&(r.dataset.bcrmState==="queued"||r.dataset.bcrmState==="uploading"); });
        if(busy){ setTimeout(tick,300); return; }
        cb();
      };
      tick();
    }
    function finalSubmit(used){
      form.querySelectorAll('input[name="staged_receipt_ids[]"],input[name="staged_receipt_amounts[]"]').forEach(function(h){ h.remove(); });
      used.forEach(function(r){
        saveAmount(r); /* اگر ثبت نهایی خطا داد، ردیف‌های بازیابی‌شده مبلغشان را دارند */
        var id=document.createElement("input"); id.type="hidden"; id.name="staged_receipt_ids[]"; id.value=r.dataset.stagedId; form.appendChild(id);
        var a=amountInput(r),am=document.createElement("input"); am.type="hidden"; am.name="staged_receipt_amounts[]"; am.value=a?a.value:""; form.appendChild(am);
      });
      /* فایل‌ها قبلاً روی سرور هستند؛ هیچ فایلی دوباره در درخواست نهایی نمی‌رود (سقف‌های سرور بی‌اثر). */
      list.querySelectorAll('input[type="file"]').forEach(function(i){ i.disabled=true; });
      submitting=true;
      form.dataset.bcrmFinalPass="1";
      if(statusBox) statusBox.textContent="در حال ثبت نهایی "+used.length+" فیش… لطفاً صفحه را نبندید.";
      /* submit() مستقیم: onsubmit (پنجره تأیید) دوباره اجرا نمی‌شود */
      HTMLFormElement.prototype.submit.call(form);
    }
    form.addEventListener("submit",function(e){
      if(form.dataset.bcrmFinalPass==="1") return;
      if(e.defaultPrevented) return; /* کاربر پنجره تأیید را لغو کرد */
      var stepEl=form.querySelector("[name=workflow_step]"),toggle=form.querySelector("[data-bcrm-receipt-toggle]");
      var receiptsOn=(!stepEl||stepEl.value==="send_finance")&&(!toggle||toggle.checked);
      form.querySelectorAll('input[name="staged_receipt_ids[]"],input[name="staged_receipt_amounts[]"]').forEach(function(h){ h.remove(); });
      if(!receiptsOn) return;
      var all=rows(),used=all.filter(hasReceipt),problems=[];
      all.forEach(function(r,i){
        var a=amountInput(r),amt=toLatin(a?a.value:"").replace(/[^0-9]/g,"");
        if(hasReceipt(r)&&!(+amt>0)) problems.push("ردیف "+(i+1)+": مبلغ فیش وارد نشده است.");
        if(!hasReceipt(r)&&amt) problems.push("ردیف "+(i+1)+": فایل فیش انتخاب نشده است.");
      });
      if(!used.length&&!problems.length) return; /* بدون فیش: سرور خودش پیام مناسب می‌دهد */
      e.preventDefault();
      if(problems.length){ alert(problems.join("\n")); return; }
      var btn=submitButton(); if(btn) btn.disabled=true;
      var fail=function(msg){ if(btn) btn.disabled=false; alert(msg); };
      used.forEach(function(r){ if(r.dataset.bcrmState==="error"&&r._bcrmFile) enqueue(r); }); /* ناموفق‌ها خودکار دوباره */
      var verifyAndSubmit=function(round){
        waitAll(used,function(){
          var failed=used.filter(function(r){ return !r.dataset.stagedId; });
          if(failed.length){
            fail(failed.length+" فیش آپلود نشد و هیچ چیزی ثبت نشده است:\n"+failed.map(function(r){ var s=r.querySelector("[data-bcrm-row-status]"); return "• ردیف "+(rows().indexOf(r)+1)+": "+(s?s.textContent.replace("تلاش دوباره","").trim():""); }).join("\n")+"\nپس از رفع مشکل دوباره «ثبت نهایی» را بزنید.");
            return;
          }
          post("bcrm_stage_list",{},null,null,function(status,j){
            if(status!==200||!j||!j.ok){ finalSubmit(used); return; } /* بررسی ممکن نشد؛ سرور خودش دقیق بررسی می‌کند */
            var onServer={}; (j.items||[]).forEach(function(it){ onServer[it.id]=1; });
            var lost=used.filter(function(r){ return !onServer[r.dataset.stagedId]; });
            if(!lost.length){ finalSubmit(used); return; }
            var reup=lost.filter(function(r){ return !!r._bcrmFile; });
            if(round<1&&reup.length===lost.length){
              lost.forEach(function(r){ delete r.dataset.stagedId; enqueue(r); });
              verifyAndSubmit(round+1);
              return;
            }
            lost.forEach(function(r){ delete r.dataset.stagedId; setRow(r,"error","این فیش روی سرور پیدا نشد (منقضی شده)؛ فایل را دوباره انتخاب کنید."); });
            fail(lost.length+" فیش روی سرور پیدا نشد؛ فایل همان ردیف‌ها را دوباره انتخاب کنید. هیچ چیزی ثبت نشده است.");
          });
        });
      };
      verifyAndSubmit(0);
    });

    refreshCfg(function(){ restore(); });
  }

  function initWorkflowForms(){
    document.querySelectorAll(".bcrm-workflow-final-form").forEach(function(form){
      initReceiptUploader(form);
      if(form.dataset.workflowReady==="1") return;
      form.dataset.workflowReady="1";
      form.classList.add("is-workflow-ready");
      function formatNumber(value){
        var raw=String(value||"").replace(/[۰-۹]/g,function(d){return "۰۱۲۳۴۵۶۷۸۹".indexOf(d);}).replace(/[٠-٩]/g,function(d){return "٠١٢٣٤٥٦٧٨٩".indexOf(d);}).replace(/[^0-9]/g,"");
        return raw ? raw.replace(/\B(?=(\d{3})+(?!\d))/g,",") : "";
      }
      function apply(){
        var stepField=form.querySelector("[name=workflow_step]");
        var financeField=form.querySelector("[name=finance_result]");
        var step=stepField?stepField.value:"";
        var finance=financeField?financeField.value:"";
        form.querySelectorAll("[data-bcrm-step]").forEach(function(el){
          el.classList.toggle("is-active",(el.getAttribute("data-bcrm-step")||"").split(/\s+/).indexOf(step)>-1);
        });
        form.querySelectorAll("[data-bcrm-finance]").forEach(function(el){
          el.classList.toggle("is-active",(el.getAttribute("data-bcrm-finance")||"")===finance);
        });
        form.querySelectorAll("[data-bcrm-receipt-fields]").forEach(function(el){
          var toggle=form.querySelector("[data-bcrm-receipt-toggle]");
          el.classList.toggle("is-active",!!(toggle&&toggle.checked));
        });
        /* v0.8.62.23 — پنل مالی به سبک فروش‌نت: مبلغ پیشنهادی = باقیمانده تا مبلغ اعلامی.
           فقط وقتی کاربر فیلد را خالی گذاشته پر می‌شود؛ تایپ کاربر بازنویسی نمی‌شود. */
        var prefill=form.querySelector("[data-bcrm-prefill]");
        if(prefill&&prefill.value===""){
          var hint=(prefill.getAttribute("placeholder")||"").replace(/^مثلا\s*/,"");
          if(hint) prefill.value=hint;
        }
      }
      form.addEventListener("change",apply);
      form.addEventListener("input",function(e){ if(e.target&&e.target.matches('input[inputmode="numeric"]')) e.target.value=formatNumber(e.target.value); });
      apply();
    });
  }
  document.addEventListener("DOMContentLoaded",initWorkflowForms);

  /* =====================================================================
     v0.8.62.21 — تقویم شمسی (date-only، بدون ساعت) برای ورودی‌های data-bcrm-jalali-date
     فرم‌های Workflow (تاریخ پیگیری / تاریخ فاکتور / تاریخ پرداخت). همان موتور تقویم
     v0.7.5 (پیگیری بعدی) است؛ مقدار انتخابی به شکل YYYY/MM/DD شمسی در input می‌نشیند
     و بک‌اند آن را به میلادی تبدیل می‌کند (Biawin_CRM_Normalizer::date).
     ===================================================================== */
  (function(){
    var months=["فروردین","اردیبهشت","خرداد","تیر","مرداد","شهریور","مهر","آبان","آذر","دی","بهمن","اسفند"];
    function faToEn(v){return String(v||"").replace(/[۰-۹]/g,function(d){return "۰۱۲۳۴۵۶۷۸۹".indexOf(d);}).replace(/[٠-٩]/g,function(d){return "٠١٢٣٤٥٦٧٨٩".indexOf(d);});}
    function pad(n){return String(n).padStart(2,"0");}
    function div(a,b){return Math.floor(a/b);}
    function mod(a,b){return a-Math.floor(a/b)*b;}
    function g2j(gy,gm,gd){var gdm=[0,31,59,90,120,151,181,212,243,273,304,334],gy2=gm>2?gy+1:gy,days=355666+365*gy+div(gy2+3,4)-div(gy2+99,100)+div(gy2+399,400)+gd+gdm[gm-1],jy=-1595+33*div(days,12053);days%=12053;jy+=4*div(days,1461);days%=1461;if(days>365){jy+=div(days-1,365);days=(days-1)%365;}var jm=days<186?1+div(days,31):7+div(days-186,30),jd=1+(days<186?mod(days,31):mod(days-186,30));return [jy,jm,jd];}
    function j2g(jy,jm,jd){jy+=1595;var days=-355668+365*jy+div(jy,33)*8+div(mod(jy,33)+3,4)+jd+(jm<7?(jm-1)*31:(jm-7)*30+186),gy=400*div(days,146097);days%=146097;if(days>36524){gy+=100*div(--days,36524);days%=36524;if(days>=365)days++;}gy+=4*div(days,1461);days%=1461;if(days>365){gy+=div(days-1,365);days=(days-1)%365;}var gd=days+1,sal=[0,31,((gy%4===0&&gy%100!==0)||gy%400===0)?29:28,31,30,31,30,31,31,30,31,30,31],gm=1;while(gm<=12&&gd>sal[gm])gd-=sal[gm++];return [gy,gm,gd];}
    function leap(jy){var g1=j2g(jy,1,1),g2=j2g(jy+1,1,1);return (Date.UTC(g2[0],g2[1]-1,g2[2])-Date.UTC(g1[0],g1[1]-1,g1[2]))/86400000===366;}
    function dim(y,m){return m<=6?31:(m<=11?30:(leap(y)?30:29));}
    function parse(v){var m=faToEn(v).match(/^(\d{4})\/(\d{1,2})\/(\d{1,2})/);return m?{y:+m[1],m:+m[2],d:+m[3]}:null;}
    function init(){document.querySelectorAll('[data-bcrm-jalali-date]').forEach(function(input){
      if(input.dataset.pickerReady)return;input.dataset.pickerReady="1";
      var wrap=input.closest('.bcrm-date-picker-wrap')||input.parentNode,btn=wrap?wrap.querySelector('[data-bcrm-open-jalali]'):null;
      function open(){document.querySelectorAll('.bcrm-jalali-popover').forEach(function(x){x.remove();});
        var now=new Date(),today=g2j(now.getFullYear(),now.getMonth()+1,now.getDate()),sel=parse(input.value)||{y:today[0],m:today[1],d:today[2]},view={y:sel.y,m:sel.m};
        var pop=document.createElement('div');pop.className='bcrm-jalali-popover';document.body.appendChild(pop);
        function close(){pop.remove();document.removeEventListener('mousedown',outside,true);}
        function outside(e){if(!pop.contains(e.target)&&e.target!==input&&e.target!==btn)close();}
        setTimeout(function(){document.addEventListener('mousedown',outside,true);},0);
        function render(){var g=j2g(view.y,view.m,1),first=new Date(g[0],g[1]-1,g[2]).getDay(),offset=(first+1)%7,html='<div class="bcrm-cal-head"><button type="button" data-next>‹</button><strong>'+months[view.m-1]+' '+view.y+'</strong><button type="button" data-prev>›</button></div><div class="bcrm-cal-week"><span>ش</span><span>ی</span><span>د</span><span>س</span><span>چ</span><span>پ</span><span>ج</span></div><div class="bcrm-cal-days">';
          for(var x=0;x<offset;x++)html+='<span></span>';
          for(var d=1;d<=dim(view.y,view.m);d++){var active=sel.y===view.y&&sel.m===view.m&&sel.d===d?' is-selected':'',ist=today[0]===view.y&&today[1]===view.m&&today[2]===d?' is-today':'';html+='<button type="button" data-day="'+d+'" class="'+active+ist+'">'+d+'</button>';}
          html+='</div><div class="bcrm-cal-actions"><button type="button" data-today>امروز</button><button type="button" class="bcrm-primary-button" data-apply>تأیید</button></div>';
          pop.innerHTML=html;
          var r=input.getBoundingClientRect(),pw=Math.min(330,window.innerWidth-24),left=Math.max(12,Math.min(window.innerWidth-pw-12,r.right-pw));
          pop.style.width=pw+'px';pop.style.left=left+'px';pop.style.top=(r.bottom+window.scrollY+6)+'px';
          pop.querySelector('[data-prev]').onclick=function(){if(--view.m<1){view.m=12;view.y--;}render();};
          pop.querySelector('[data-next]').onclick=function(){if(++view.m>12){view.m=1;view.y++;}render();};
          pop.querySelectorAll('[data-day]').forEach(function(b){b.onclick=function(){sel.y=view.y;sel.m=view.m;sel.d=+b.dataset.day;render();};});
          pop.querySelector('[data-today]').onclick=function(){sel.y=today[0];sel.m=today[1];sel.d=today[2];view.y=sel.y;view.m=sel.m;render();};
          pop.querySelector('[data-apply]').onclick=function(){input.value=sel.y+'/'+pad(sel.m)+'/'+pad(sel.d);input.dispatchEvent(new Event('change',{bubbles:true}));input.dispatchEvent(new Event('input',{bubbles:true}));close();};
        }
        render();
      }
      input.addEventListener('click',open);if(btn)btn.addEventListener('click',open);
    });}
    window.bcrmInitJalali=init; /* v0.8.62.28: فرم نتیجه مالی داخل پنجره بررسی فیش هم تقویم شمسی دارد */
    if(document.readyState==="loading") document.addEventListener("DOMContentLoaded",init); else init();
  })();

  /* =====================================================================
     v0.8.62.8 — فیلتر ستونی اکسلی برای جدول «عودت‌ها».
     هیچ فیلتری در جاوااسکریپت انجام نمی‌شود؛ پنل‌ها فقط پارامتر URL را می‌سازند
     و صفحه با WHERE سمت سرور (SQL) دوباره رندر می‌شود.
     ===================================================================== */
  function initRefundFilters(){
    var table=document.querySelector(".bcrm-rf-table");
    if(!table||table.dataset.rfReady==="1") return;
    table.dataset.rfReady="1";
    var COLS={
      name:{label:"مشتری",kind:"text"},
      code:{label:"CustomerID",kind:"text"},
      phone:{label:"موبایل",kind:"text"},
      invoices:{label:"مجموع فاکتورها",kind:"number"},
      refund:{label:"مبلغ عودت",kind:"number"},
      used:{label:"استفاده شده",kind:"number"},
      balance:{label:"مانده",kind:"number"},
      tags:{label:"تگ‌ها",kind:"categorical"}
    };
    var COL_PARAMS={
      name:["name_q","name_m"], code:["code_q","code_m"], phone:["phone_q","phone_m"],
      invoices:["inv_op","inv_a","inv_b"], refund:["r_op","r_a","r_b"], used:["u_sign"],
      balance:["b_op","b_a","b_b","b_sign"], tags:["tag_mode"]
    };
    var OPS=[["","همه"],["eq","برابر است با"],["gt","بزرگ‌تر از"],["gte","بزرگ‌تر یا مساوی"],["lt","کوچک‌تر از"],["lte","کوچک‌تر یا مساوی"],["between","بین"]];
    function sp(){ return new URLSearchParams(window.location.search); }
    function nav(mutate){
      var s=sp(); mutate(s); s["delete"]?s["delete"]("r_p"):s.delete("r_p");
      var qs=s.toString();
      window.location.search=qs?"?"+qs:"?";
    }
    function faToLatin(v){
      return String(v||"")
        .replace(/[۰-۹]/g,function(d){return String("۰۱۲۳۴۵۶۷۸۹".indexOf(d));})
        .replace(/[٠-٩]/g,function(d){return String("٠١٢٣٤٥٦٧٨٩".indexOf(d));});
    }
    var pop=null, popCol=null;
    function closePop(){
      if(pop){ pop.remove(); pop=null; }
      if(popCol){ popCol.setAttribute("aria-expanded","false"); popCol=null; }
      document.removeEventListener("keydown",onKey,true);
    }
    function onKey(e){ if(e.key==="Escape") closePop(); }
    function openPop(btn){
      closePop();
      var col=btn.getAttribute("data-col");
      var cfg=COLS[col]; if(!cfg) return;
      popCol=btn; btn.setAttribute("aria-expanded","true");
      pop=document.createElement("div");
      pop.className="bcrm-rf-pop";
      pop.setAttribute("role","dialog");
      pop.innerHTML='<div class="bcrm-rf-pop-title">فیلتر '+cfg.label+'<button type="button" class="bcrm-rf-pop-x" title="بستن">✕</button></div>';
      var body=document.createElement("div");
      body.className="bcrm-rf-pop-body";
      buildBody(body,col,cfg);
      pop.appendChild(body);
      var actions=document.createElement("div");
      actions.className="bcrm-rf-pop-actions";
      actions.innerHTML='<button type="button" class="bcrm-rf-pop-cancel">لغو</button>'
        +'<button type="button" class="bcrm-primary-button is-small bcrm-rf-pop-apply">اعمال فیلتر</button>';
      pop.appendChild(actions);
      document.body.appendChild(pop);
      var r=btn.getBoundingClientRect(), pw=280, ph=pop.offsetHeight||300;
      var left=Math.min(Math.max(8,r.left+r.width/2-pw/2),window.innerWidth-pw-8);
      var top=r.bottom+6;
      if(top+ph>window.innerHeight-8) top=Math.max(8,r.top-ph-6);
      pop.style.left=left+"px"; pop.style.top=top+"px"; pop.style.width=pw+"px";
      var focusEl=pop.querySelector("input[type=search],input[data-f=a],input[data-f=search]");
      if(focusEl) focusEl.focus();
      pop.addEventListener("click",function(e){
        var t=e.target;
        if(t.closest(".bcrm-rf-pop-x")||t.closest(".bcrm-rf-pop-cancel")){ e.preventDefault(); closePop(); return; }
        var sortBtn=t.closest("[data-sort]");
        if(sortBtn){ e.preventDefault(); var d=sortBtn.getAttribute("data-sort"); nav(function(s){ s.set("r_sort",col); s.set("r_dir",d); }); return; }
        var signBtn=t.closest("[data-sign]");
        if(signBtn){ e.preventDefault(); var v=signBtn.getAttribute("data-sign"); nav(function(s){ COL_PARAMS[col].forEach(function(k){s["delete"](k);}); s.set("b_sign",v); }); return; }
        var clearBtn=t.closest("[data-clear-col]");
        if(clearBtn){ e.preventDefault(); nav(function(s){ COL_PARAMS[col].forEach(function(k){s["delete"](k);}); }); return; }
        if(t.closest(".bcrm-rf-pop-apply")){ e.preventDefault(); applyPop(col); return; }
        var opSel=t.closest("select[data-f=op]");
        if(opSel){ syncOpRows(pop,col); }
      });
      pop.addEventListener("keydown",function(e){
        if(e.key==="Enter"&&(e.target.matches("input"))){ e.preventDefault(); applyPop(col); }
      });
      document.addEventListener("keydown",onKey,true);
      setTimeout(function(){
        document.addEventListener("click",function h(e){
          if(!pop) { document.removeEventListener("click",h,true); return; }
          if(pop.contains(e.target)||e.target===btn||btn.contains(e.target)) return;
          closePop(); document.removeEventListener("click",h,true);
        },true);
      },0);
    }
    function syncOpRows(pop,col){
      var sel=pop.querySelector("select[data-f=op]");
      if(!sel) return;
      var rowA=pop.querySelector("[data-row=a]"), rowB=pop.querySelector("[data-row=b]");
      if(rowA) rowA.hidden=(sel.value==="");
      if(rowB) rowB.hidden=(sel.value!=="between");
    }
    function applyPop(col){
      var cfg=COLS[col]; if(!pop||!cfg) return;
      nav(function(s){
        COL_PARAMS[col].forEach(function(k){ s["delete"](k); });
        if(cfg.kind==="text"){
          var val=(pop.querySelector("input[data-f=search]")||{}).value||"";
          var mode=(pop.querySelector("input[name=op]:checked")||{}).value||"contains";
          if(String(val).trim()!==""){ s.set(col==="name"?"name_q":col+"_q",String(val).trim()); s.set(col+"_m",mode==="exact"?"exact":"contains"); }
        } else if(col==="used"){
          var uv=(pop.querySelector("input[name=us]:checked")||{}).value||"";
          if(uv!=="") s.set("u_sign",uv);
        } else if(cfg.kind==="number"){
          var sel=pop.querySelector("select[data-f=op]");
          var op=sel?sel.value:"";
          var a=faToLatin((pop.querySelector("input[data-f=a]")||{}).value||"").replace(/,/g,"").trim();
          var b=faToLatin((pop.querySelector("input[data-f=b]")||{}).value||"").replace(/,/g,"").trim();
          var pre=col==="invoices"?"inv":(col==="refund"?"r":"b");
          if(op!==""&&a!==""&&(op!=="between"||b!=="")){ s.set(pre+"_op",op); s.set(pre+"_a",a); if(op==="between") s.set(pre+"_b",b); }
        } else if(cfg.kind==="categorical"){
          var tm=(pop.querySelector("input[name=tm]:checked")||{}).value||"";
          if(tm!=="") s.set("tag_mode",tm);
        }
      });
    }
    function buildBody(root,col,cfg){
      var s=sp();
      if(cfg.kind==="text"){
        var q=s.get(col+"_q")||"", m=s.get(col+"_m")||"contains";
        var w=document.createElement("div");
        w.className="bcrm-rf-pop-sec";
        w.innerHTML='<input type="search" data-f="search" value="" placeholder="جستجو در مقادیر…">'
          +'<label class="bcrm-rf-pop-opt"><input type="radio" name="op" value="contains"'+(m!=="exact"?" checked":"")+'> شامل عبارت باشد</label>'
          +'<label class="bcrm-rf-pop-opt"><input type="radio" name="op" value="exact"'+(m==="exact"?" checked":"")+'> دقیقاً برابر باشد</label>'
          +'<div class="bcrm-rf-pop-hint">مثال: '+ (col==="phone"?"09394434971":(col==="code"?"C011644":"بخشی از نام مشتری")) +'</div>';
        var inp=w.querySelector("input[data-f=search]"); inp.value=q;
        root.appendChild(w);
      } else if(col==="used"){
        var uc=s.get("u_sign")||"";
        var wu=document.createElement("div");
        wu.className="bcrm-rf-pop-sec";
        wu.innerHTML=''
          +'<label class="bcrm-rf-pop-opt"><input type="radio" name="us" value=""'+(uc===""?" checked":"")+'> همه</label>'
          +'<label class="bcrm-rf-pop-opt"><input type="radio" name="us" value="yes"'+(uc==="yes"?" checked":"")+'> دارای «استفاده شده»</label>'
          +'<label class="bcrm-rf-pop-opt"><input type="radio" name="us" value="no"'+(uc==="no"?" checked":"")+'> بدون «استفاده شده»</label>';
        root.appendChild(wu);
      } else if(cfg.kind==="number"){
        var pre=col==="invoices"?"inv":(col==="refund"?"r":"b");
        var op=s.get(pre+"_op")||"", a=s.get(pre+"_a")||"", b=s.get(pre+"_b")||"";
        var w2=document.createElement("div");
        w2.className="bcrm-rf-pop-sec";
        var opts=""; OPS.forEach(function(o){ opts+='<option value="'+o[0]+'"'+(op===o[0]?" selected":"")+">"+o[1]+"</option>"; });
        w2.innerHTML='<select data-f="op">'+opts+'</select>'
          +'<div data-row="a" hidden><label class="bcrm-rf-pop-lbl">مقدار<input type="text" inputmode="numeric" data-f="a" value=""></label></div>'
          +'<div data-row="b" hidden><label class="bcrm-rf-pop-lbl">مقدار دوم<input type="text" inputmode="numeric" data-f="b" value=""></label></div>';
        w2.querySelector("input[data-f=a]").value=a;
        w2.querySelector("input[data-f=b]").value=b;
        root.appendChild(w2);
        if(col==="balance"){
          var sg=s.get("b_sign")||"";
          var w3=document.createElement("div");
          w3.className="bcrm-rf-pop-sec bcrm-rf-pop-quick";
          w3.innerHTML='<div class="bcrm-rf-pop-hint">میان‌بر:</div>'
            +'<button type="button" data-sign="neg"'+(sg==="neg"?" class=is-on":"")+'>منفی</button>'
            +'<button type="button" data-sign="pos"'+(sg==="pos"?" class=is-on":"")+'>مثبت / صفر</button>'
            +'<button type="button" data-sign="zero"'+(sg==="zero"?" class=is-on":"")+'>صفر</button>';
          root.appendChild(w3);
        }
        syncOpRows(root,col);
      } else if(cfg.kind==="categorical"){
        var cur=s.get("tag_mode")||"";
        var w4=document.createElement("div");
        w4.className="bcrm-rf-pop-sec";
        w4.innerHTML=''
          +'<label class="bcrm-rf-pop-opt"><input type="radio" name="tm" value=""'+(cur===""?" checked":"")+'> همه</label>'
          +'<label class="bcrm-rf-pop-opt"><input type="radio" name="tm" value="T_CONFIRMED"'+(cur==="T_CONFIRMED"?" checked":"")+'> عودت قطعی (اکسل)</label>'
          +'<label class="bcrm-rf-pop-opt"><input type="radio" name="tm" value="T_REPORTED"'+(cur==="T_REPORTED"?" checked":"")+'> عودت اعلامی (کارشناس)</label>'
          +'<label class="bcrm-rf-pop-opt"><input type="radio" name="tm" value="T_BOTH"'+(cur==="T_BOTH"?" checked":"")+'> هر دو</label>'
          +'<label class="bcrm-rf-pop-opt"><input type="radio" name="tm" value="T_NONE"'+(cur==="T_NONE"?" checked":"")+'> بدون تگ</label>';
        root.appendChild(w4);
      }
      var sorted=sp().get("r_sort")===col&&sp().get("r_dir");
      var sw=document.createElement("div");
      sw.className="bcrm-rf-pop-sec bcrm-rf-pop-sort";
      sw.innerHTML='<button type="button" data-sort="asc"'+(sorted&&sp().get("r_dir")==="asc"?" class=is-on":"")+'>↑ مرتب‌سازی صعودی</button>'
        +'<button type="button" data-sort="desc"'+(sorted&&sp().get("r_dir")==="desc"?" class=is-on":"")+'>↓ مرتب‌سازی نزولی</button>';
      root.appendChild(sw);
      var hasFilter=COL_PARAMS[col].some(function(k){ return (s.get(k)||"")!==""; });
      if(hasFilter){
        var cw=document.createElement("div");
        cw.className="bcrm-rf-pop-sec";
        cw.innerHTML='<button type="button" class="bcrm-rf-pop-clearcol" data-clear-col="1">حذف فیلتر این ستون ✕</button>';
        root.appendChild(cw);
      }
    }
    table.querySelectorAll(".bcrm-rf-fbtn").forEach(function(btn){
      btn.addEventListener("click",function(e){
        e.preventDefault(); e.stopPropagation();
        if(popCol===btn){ closePop(); return; }
        openPop(btn);
      });
    });
    window.addEventListener("scroll",function(){ if(pop) closePop(); },{passive:true});
    window.addEventListener("resize",function(){ if(pop) closePop(); });
  }
  document.addEventListener("DOMContentLoaded",initRefundFilters);

  /* =====================================================================
     v0.8.62.28 — پنجره بررسی فیش مالی (به سبک پنل مالی فروش‌نت):
     همان پنجره‌ای که فیش را نشان می‌دهد، محل تصمیم است — تأیید فیش با مبلغ، رد با دلیل،
     بازگردانی به «بررسی‌نشده»، رفتن به فیش قبلی/بعدی و ثبت نتیجه نهایی مالی پرونده؛
     همه با AJAX و بدون رفرش. دکمه‌ها: [data-bcrm-fin-review][data-case-id][data-attachment-id?]
     ===================================================================== */
  (function(){
    var M=null,S={caseId:0,data:null,idx:0,zoom:1,rot:0,busy:false};
    function esc(s){ return String(s==null?"":s).replace(/[&<>"']/g,function(c){ return {"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c]; }); }
    function num(n){ return n==null||n===""?"—":Math.round(+n||0).toString().replace(/\B(?=(\d{3})+(?!\d))/g,","); }
    function cfg(){ var A=window.BCRM_ADMIN||{},B=window.BiawinCRM||{}; return {url:A.ajaxUrl||B.ajaxUrl||"",nonce:A.finReviewNonce||B.finReviewNonce||""}; }
    function post(action,data,cb){
      var c=cfg(),fd=new FormData();
      if(!c.url){ cb(false,{message:"آدرس سرور در صفحه نیست؛ صفحه را دوباره بارگذاری کنید."}); return; }
      fd.append("action",action); fd.append("nonce",c.nonce); fd.append("case_id",S.caseId);
      for(var k in data){ if(Object.prototype.hasOwnProperty.call(data,k)) fd.append(k,data[k]==null?"":data[k]); }
      var x=new XMLHttpRequest(); x.open("POST",c.url,true);
      x.onreadystatechange=function(){
        if(x.readyState!==4) return;
        var j=null; try{ j=JSON.parse(x.responseText); }catch(e){}
        if(x.status===200&&j&&j.ok){ cb(true,j); return; }
        cb(false,j||{message:x.status===0?"ارتباط با سرور برقرار نشد؛ اینترنت را بررسی کنید.":"خطای سرور (کد "+x.status+")."});
      };
      x.send(fd);
    }
    function q(sel){ return M.querySelector(sel); }
    function ensure(){
      if(M) return;
      M=document.createElement("div");
      M.className="bcrm-frv"; M.setAttribute("role","dialog"); M.setAttribute("aria-modal","true"); M.setAttribute("dir","rtl"); M.hidden=true;
      M.innerHTML='<div class="bcrm-frv-backdrop" data-frv-close></div><div class="bcrm-frv-card">'+
        '<div class="bcrm-frv-head"><div class="bcrm-frv-title"><h3>بررسی فیش و تأیید مالی</h3><p data-frv-sub></p></div>'+
        '<div class="bcrm-frv-nav"><button type="button" class="button" data-frv-prev>→ قبلی</button><span data-frv-count></span><button type="button" class="button" data-frv-next>بعدی ←</button></div>'+
        '<button type="button" class="bcrm-frv-x" data-frv-close aria-label="بستن">×</button></div>'+
        '<div class="bcrm-frv-body"><aside class="bcrm-frv-side"><div class="bcrm-frv-msg" data-frv-msg hidden></div><div data-frv-side></div></aside>'+
        '<section class="bcrm-frv-media"><div class="bcrm-frv-tools" data-frv-tools></div><div class="bcrm-frv-stage" data-frv-stage></div><div class="bcrm-frv-thumbs" data-frv-thumbs></div></section></div></div>';
      document.body.appendChild(M);
      M.addEventListener("click",onClick);
      M.addEventListener("change",function(e){ if(e.target&&e.target.name==="finance_result") syncDecision(); });
      M.addEventListener("input",function(e){ var t=e.target; if(t&&t.getAttribute("inputmode")==="numeric"){ var raw=String(t.value).replace(/[۰-۹]/g,function(d){return "۰۱۲۳۴۵۶۷۸۹".indexOf(d);}).replace(/[٠-٩]/g,function(d){return "٠١٢٣٤٥٦٧٨٩".indexOf(d);}).replace(/[^0-9]/g,""); t.value=raw?raw.replace(/\B(?=(\d{3})+(?!\d))/g,","):""; } });
      document.addEventListener("keydown",function(e){
        if(!M||M.hidden) return;
        var tag=(e.target&&e.target.tagName)||"";
        if(e.key==="Escape"){ close(); return; }
        if(/INPUT|TEXTAREA|SELECT/.test(tag)) return;
        if(e.key==="ArrowLeft") go(1); else if(e.key==="ArrowRight") go(-1);
      });
    }
    function msg(text,type){ var b=q("[data-frv-msg]"); b.textContent=text||""; b.hidden=!text; b.className="bcrm-frv-msg"+(type?" is-"+type:""); }
    function open(caseId,attId){
      ensure();
      var shell=document.querySelector(".bcrm-portal-shell");
      M.classList.toggle("is-dark",!!(shell&&shell.classList.contains("is-dark")));
      S.caseId=+caseId; S.data=null; S.idx=0; S.zoom=1; S.rot=0;
      M.hidden=false; document.documentElement.classList.add("bcrm-frv-open");
      msg("");
      q("[data-frv-side]").innerHTML='<div class="bcrm-frv-loading">در حال دریافت اطلاعات پرونده…</div>';
      q("[data-frv-stage]").innerHTML=""; q("[data-frv-thumbs]").innerHTML=""; q("[data-frv-tools]").innerHTML=""; q("[data-frv-sub]").textContent=""; q("[data-frv-count]").textContent="";
      post("bcrm_fin_case_review",{},function(ok,j){
        if(!ok){ q("[data-frv-side]").innerHTML='<div class="bcrm-frv-empty">'+esc(j.message||"دریافت اطلاعات ناموفق بود.")+'</div>'; return; }
        S.data=j;
        var i=0; (j.receipts||[]).forEach(function(r,k){ if(attId&&+r.id===+attId) i=k; });
        if(!attId){ (j.receipts||[]).some(function(r,k){ if(r.review.status==="pending"){ i=k; return true; } return false; }); }
        S.idx=i; render();
      });
    }
    function close(){
      if(!M) return;
      M.hidden=true; document.documentElement.classList.remove("bcrm-frv-open");
      q("[data-frv-stage]").innerHTML=""; /* iframe/تصویر سنگین آزاد شود */
    }
    function cur(){ var r=S.data&&S.data.receipts; return r&&r.length?r[S.idx]:null; }
    function go(d){ var r=S.data&&S.data.receipts; if(!r||!r.length) return; S.idx=(S.idx+d+r.length)%r.length; S.zoom=1; S.rot=0; msg(""); render(); }
    function render(){ renderHead(); renderMedia(); renderSide(); }
    function renderHead(){
      var d=S.data,c=d.case,n=d.receipts.length;
      q("[data-frv-sub]").textContent=c.code+" · "+c.customer_name+" · "+c.status_label;
      q("[data-frv-count]").textContent=n?("فیش "+(S.idx+1)+" از "+n):"بدون فیش";
      q("[data-frv-prev]").disabled=n<2; q("[data-frv-next]").disabled=n<2;
      var t="";
      d.receipts.forEach(function(r,k){
        t+='<button type="button" class="bcrm-frv-thumb is-'+esc(r.review.status)+(k===S.idx?" is-current":"")+'" data-frv-go="'+k+'" title="'+esc(r.title)+'"><b>'+(k+1)+'</b><span>'+esc(r.amount!=null?num(r.amount):"—")+'</span></button>';
      });
      q("[data-frv-thumbs]").innerHTML=t;
    }
    function renderMedia(){
      var r=cur(),stage=q("[data-frv-stage]"),tools=q("[data-frv-tools]");
      if(!r){ stage.innerHTML='<div class="bcrm-frv-empty">برای این پرونده هنوز فیشی ثبت نشده است.</div>'; tools.innerHTML=""; return; }
      var link=r.url?'<a class="button" href="'+esc(r.url)+'" target="_blank" rel="noopener">باز کردن در تب جدید</a><a class="button" href="'+esc(r.url)+'" download>دانلود</a>':"";
      if(r.kind==="image"&&r.url){
        tools.innerHTML='<button type="button" class="button" data-frv-zoom="-1" title="کوچک‌نمایی">−</button><button type="button" class="button" data-frv-zoom="0" title="اندازه اصلی">۱:۱</button><button type="button" class="button" data-frv-zoom="1" title="بزرگ‌نمایی">+</button><button type="button" class="button" data-frv-rotate title="چرخش">⟳</button>'+link;
        stage.innerHTML='<img class="bcrm-frv-img" alt="'+esc(r.title)+'" src="'+esc(r.url)+'">';
        applyTransform();
      } else if(r.kind==="pdf"&&r.url){
        tools.innerHTML=link;
        stage.innerHTML='<iframe class="bcrm-frv-frame" src="'+esc(r.url)+'" title="'+esc(r.title)+'"></iframe>';
      } else {
        tools.innerHTML=link;
        stage.innerHTML='<div class="bcrm-frv-file"><div class="bcrm-frv-file-icon">📄</div><strong>'+esc(r.title)+'</strong><span dir="ltr">'+esc(r.mime||"")+'</span><p>پیش‌نمایش این فرمت در مرورگر ممکن نیست؛ با «باز کردن در تب جدید» یا «دانلود» فایل را ببینید و همین‌جا تأیید/رد کنید.</p></div>';
      }
    }
    function applyTransform(){ var img=M.querySelector(".bcrm-frv-img"); if(img){ img.style.transform="rotate("+S.rot+"deg) scale("+S.zoom+")"; img.classList.toggle("is-zoomed",S.zoom>1); } }
    function row(label,value,cls){ return '<div class="bcrm-frv-kv'+(cls?" "+cls:"")+'"><small>'+esc(label)+'</small><strong>'+value+'</strong></div>'; }
    function renderSide(){
      var d=S.data,c=d.case,r=cur(),p=d.perms,h="";
      if(!p.review) h+='<div class="bcrm-frv-note">'+(["FINANCE_REVIEW_PENDING","FINANCE_ASSIGNED","FINANCE_UNDER_REVIEW"].indexOf(c.status)>-1?"شما دسترسی تأیید/رد فیش این پرونده را ندارید؛ فقط مشاهده.":"این پرونده در صف مالی نیست («"+esc(c.status_label)+"»)؛ فیش‌ها فقط قابل مشاهده‌اند.")+'</div>';
      h+='<div class="bcrm-frv-grid">'+
        row("مشتری",esc(c.customer_name))+row("Customer ID",'<span dir="ltr">'+esc(c.customer_code||"—")+'</span>')+
        row("موبایل",'<span dir="ltr">'+esc((c.phones||[]).join("، ")||"—")+'</span>')+row("مدیر فروش",esc(c.sales_manager||"—"))+
        row("مبلغ اعلامی مشتری",num(c.reported_amount))+row("مجموع فاکتورهای ثبتی",num(c.registered_amount))+
        row("جمع فیش‌ها",num(c.receipts_total))+row("جمع تأییدشده مالی",num(c.approved_total),"is-ok")+
        row("باقیمانده تا مبلغ اعلامی",num(c.remaining),c.remaining>0?"is-bad":"")+row("اختلاف پرونده",num(c.difference))+
      '</div>';
      h+='<div class="bcrm-frv-counts"><span class="bcrm-fin-badge is-pending">بررسی‌نشده: '+d.counts.pending+'</span><span class="bcrm-fin-badge is-approved">تأییدشده: '+d.counts.approved+'</span><span class="bcrm-fin-badge is-rejected">ردشده: '+d.counts.rejected+'</span></div>';
      if(c.description) h+='<div class="bcrm-frv-desc"><small>توضیحات رفع ناقصی</small><p>'+esc(c.description)+'</p></div>';
      if(r){
        var rv=r.review;
        h+='<section class="bcrm-frv-receipt"><div class="bcrm-frv-receipt-head"><strong>'+esc(r.title)+'</strong><span class="bcrm-fin-badge is-'+esc(rv.status)+'">'+esc(rv.label)+'</span></div>'+
          '<div class="bcrm-frv-grid">'+row("مبلغ اعلامی فیش",num(r.amount))+row("بارگذاری",esc(r.uploaded_at)+(r.uploaded_by?" · "+esc(r.uploaded_by):""))+'</div>'+
          (r.note?'<p class="bcrm-frv-receipt-note">'+esc(r.note)+'</p>':"")+
          (rv.status!=="pending"?'<p class="bcrm-frv-review-by">'+esc(rv.label)+(rv.amount!=null?" با مبلغ "+num(rv.amount):"")+" — "+esc(rv.by||"")+" · "+esc(rv.at||"")+(rv.note?"<br>«"+esc(rv.note)+"»":"")+'</p>':"");
        if(p.review){
          var amt=rv.amount!=null?rv.amount:(r.amount!=null?r.amount:"");
          h+='<div class="bcrm-frv-actions">'+
            '<label>مبلغ تأییدشده این فیش<input type="text" inputmode="numeric" data-frv-amount value="'+esc(amt===""?"":num(amt))+'"></label>'+
            '<button type="button" class="button button-primary bcrm-frv-approve" data-frv-act="approve">✓ تأیید این فیش</button>'+
            '<label>دلیل رد این فیش<textarea rows="2" data-frv-note placeholder="مثلاً: مبلغ با فیش مغایر است / واریز در حساب پیدا نشد">'+esc(rv.status==="rejected"?rv.note:"")+'</textarea></label>'+
            '<button type="button" class="button bcrm-frv-reject" data-frv-act="reject">✕ رد این فیش</button>'+
            (rv.status!=="pending"?'<button type="button" class="button" data-frv-act="reset">بازگردانی به «بررسی‌نشده»</button>':"")+
          '</div>';
        }
        h+='</section>';
      }
      if(p.decide) h+=decisionForm();
      if(d.history&&d.history.length){
        h+='<details class="bcrm-frv-history"><summary>سوابق پرونده ('+d.history.length+')</summary>';
        d.history.forEach(function(a){ h+='<article><strong>'+esc(a.label)+'</strong><small>'+esc(a.by)+' · '+esc(a.at)+'</small>'+(a.note?'<p>'+esc(a.note)+'</p>':"")+'</article>'; });
        h+='</details>';
      }
      q("[data-frv-side]").innerHTML=h;
      if(p.decide){ syncDecision(); if(typeof window.bcrmInitJalali==="function") window.bcrmInitJalali(); }
    }
    function decisionForm(){
      var d=S.data,c=d.case,suggest=c.approved_total>0?c.approved_total:c.receipts_total,opts="",reasons="";
      d.receipts.forEach(function(r,k){ opts+='<option value="'+r.id+'"'+(k===S.idx?" selected":"")+'>فیش '+(k+1)+' — '+esc(r.amount!=null?num(r.amount):r.title)+'</option>'; });
      for(var k in d.reject_reasons){ if(Object.prototype.hasOwnProperty.call(d.reject_reasons,k)) reasons+='<option value="'+esc(k)+'">'+esc(d.reject_reasons[k])+'</option>'; }
      var warn=d.counts.pending?'<p class="bcrm-frv-warn">'+d.counts.pending+' فیش هنوز بررسی نشده است.</p>':"";
      return '<details class="bcrm-frv-decision" open><summary>ثبت نتیجه نهایی مالی پرونده</summary>'+warn+
        '<label>نتیجه مالی<select name="finance_result"><option value="">انتخاب نتیجه</option><option value="without_invoice">تأیید مالی بدون فاکتور</option><option value="with_invoice">تأیید مالی با فاکتور</option><option value="rejected">عدم تأیید مالی</option></select></label>'+
        '<div data-frv-when="without_invoice"><label>مبلغ تأییدشده<input type="text" inputmode="numeric" name="approved_amount" value="'+esc(suggest>0?num(suggest):"")+'"></label>'+
          '<label>تاریخ پرداخت<div class="bcrm-date-picker-wrap"><input type="text" name="payment_date" data-bcrm-jalali-date readonly placeholder="انتخاب تاریخ شمسی"><button type="button" class="bcrm-date-picker-button" data-bcrm-open-jalali aria-label="تقویم">📅</button></div></label>'+
          '<label>شماره پیگیری<input type="text" name="tracking_number"></label><label>بانک/درگاه<input type="text" name="bank_or_gateway"></label></div>'+
        '<div data-frv-when="with_invoice"><label>فیش مرتبط<select name="receipt_attachment_id">'+opts+'</select></label><label>شماره فاکتور<input type="text" name="invoice_number"></label>'+
          '<label>شماره تماس مشتری<input type="tel" name="customer_phone" value="'+esc((c.phones||[])[0]||"")+'"></label>'+
          '<label>تاریخ فاکتور<div class="bcrm-date-picker-wrap"><input type="text" name="invoice_date" data-bcrm-jalali-date readonly placeholder="انتخاب تاریخ شمسی"><button type="button" class="bcrm-date-picker-button" data-bcrm-open-jalali aria-label="تقویم">📅</button></div></label>'+
          '<label>مبلغ فاکتور<input type="text" inputmode="numeric" name="invoice_amount" value="'+esc(suggest>0?num(suggest):"")+'"></label></div>'+
        '<div data-frv-when="rejected"><label>دلیل عدم تأیید<select name="reject_reason"><option value="">انتخاب دلیل</option>'+reasons+'</select></label></div>'+
        '<label>توضیحات مالی<textarea name="description" rows="2"></textarea></label>'+
        '<button type="button" class="button button-primary" data-frv-act="decide">ثبت نتیجه مالی و برگرداندن پرونده</button></details>';
    }
    function syncDecision(){
      var sel=M.querySelector('select[name="finance_result"]'),v=sel?sel.value:"";
      M.querySelectorAll("[data-frv-when]").forEach(function(el){ el.hidden=el.getAttribute("data-frv-when")!==v; });
    }
    function updatePageBadges(){
      (S.data.receipts||[]).forEach(function(r){
        document.querySelectorAll('[data-bcrm-review-badge="'+r.id+'"]').forEach(function(b){ b.textContent=r.review.label; b.className="bcrm-fin-badge is-"+r.review.status; });
      });
    }
    function setBusy(on){ S.busy=on; M.querySelectorAll("[data-frv-act]").forEach(function(b){ b.disabled=on; }); }
    function review(decision){
      var r=cur(); if(!r||S.busy) return;
      var data={attachment_id:r.id,decision:decision};
      if(decision==="approve") data.amount=(M.querySelector("[data-frv-amount]")||{}).value||"";
      if(decision==="reject"){ data.note=((M.querySelector("[data-frv-note]")||{}).value||"").trim(); if(!data.note){ msg("دلیل رد این فیش را بنویسید.","error"); return; } }
      setBusy(true); msg("در حال ثبت…");
      post("bcrm_fin_receipt_review",data,function(ok,j){
        setBusy(false);
        if(!ok){ msg(j.message||"ثبت نشد.","error"); return; }
        var keepId=r.id; S.data=j; updatePageBadges();
        var list=S.data.receipts,next=-1;
        list.forEach(function(x,k){ if(x.id===keepId) S.idx=k; });
        if(decision!=="reset"){ for(var s=1;s<=list.length;s++){ var k=(S.idx+s)%list.length; if(list[k].review.status==="pending"){ next=k; break; } } }
        if(next>-1&&next!==S.idx){ S.idx=next; S.zoom=1; S.rot=0; render(); msg((j.message||"ثبت شد")+" — فیش بعدیِ بررسی‌نشده باز شد.","ok"); }
        else { render(); msg(j.message||"ثبت شد.","ok"); }
      });
    }
    function decide(){
      if(S.busy) return;
      var box=M.querySelector(".bcrm-frv-decision"),data={},res=(box.querySelector('select[name="finance_result"]')||{}).value||"";
      if(!res){ msg("نتیجه مالی را انتخاب کنید.","error"); return; }
      box.querySelectorAll("input[name],select[name],textarea[name]").forEach(function(el){ var w=el.closest("[data-frv-when]"); if(w&&w.hidden) return; data[el.name]=el.value; });
      var pending=S.data.counts.pending;
      if(!window.confirm((pending?pending+" فیش هنوز بررسی نشده است.\n":"")+"نتیجه مالی «"+box.querySelector('select[name="finance_result"] option:checked').textContent+"» ثبت و پرونده به رفع ناقصی برگردد؟")) return;
      setBusy(true); msg("در حال ثبت نتیجه مالی…");
      post("bcrm_fin_case_decide",data,function(ok,j){
        setBusy(false);
        if(!ok){ msg(j.message||"ثبت نتیجه مالی ناموفق بود.","error"); return; }
        msg((j.message||"نتیجه مالی ثبت شد.")+" صفحه به‌روزرسانی می‌شود…","ok");
        setTimeout(function(){ window.location.reload(); },1200);
      });
    }
    function onClick(e){
      var t=e.target;
      if(t.closest("[data-frv-close]")){ close(); return; }
      if(t.closest("[data-frv-prev]")){ go(-1); return; }
      if(t.closest("[data-frv-next]")){ go(1); return; }
      var g=t.closest("[data-frv-go]"); if(g){ S.idx=+g.getAttribute("data-frv-go"); S.zoom=1; S.rot=0; msg(""); render(); return; }
      var z=t.closest("[data-frv-zoom]"); if(z){ var v=+z.getAttribute("data-frv-zoom"); S.zoom=v===0?1:Math.max(.5,Math.min(4,S.zoom+(v*.5))); applyTransform(); return; }
      if(t.closest("[data-frv-rotate]")){ S.rot=(S.rot+90)%360; applyTransform(); return; }
      if(t.classList&&t.classList.contains("bcrm-frv-img")){ S.zoom=S.zoom>1?1:2; applyTransform(); return; }
      var a=t.closest("[data-frv-act]"); if(!a) return;
      var act=a.getAttribute("data-frv-act");
      if(act==="decide") decide(); else review(act);
    }
    document.addEventListener("click",function(e){
      var b=e.target.closest&&e.target.closest("[data-bcrm-fin-review]");
      if(!b||(M&&M.contains(b))) return;
      e.preventDefault();
      open(b.getAttribute("data-case-id"),b.getAttribute("data-attachment-id")||0);
    });
  })();
})();
