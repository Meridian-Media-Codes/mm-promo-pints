(function(){
  function qs(sel, root){ return (root||document).querySelector(sel); }

  function setMsg(el, text, kind){
    if(!el) return;
    el.innerHTML = '<div class="mmpp-msg '+(kind||'')+'">'+escapeHtml(text)+'</div>';
  }

  function escapeHtml(s){
    return String(s)
      .replace(/&/g,'&amp;')
      .replace(/</g,'&lt;')
      .replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;')
      .replace(/'/g,'&#039;');
  }

  async function postJson(url, payload){
    const res = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': (window.MMPP && MMPP.nonce) ? MMPP.nonce : ''
      },
      body: JSON.stringify(payload)
    });
    const data = await res.json().catch(()=> ({}));
    return { ok: res.ok, status: res.status, data };
  }

  function initClaim(){
    const wrap = qs('.mmpp-wrap[data-token]');
    if(!wrap) return;

    const token = wrap.getAttribute('data-token');
    const pinRequired = wrap.getAttribute('data-pin-required') === '1';
    const qrEnabled = wrap.getAttribute('data-qr-enabled') === '1';

    const redeemBtn = qs('#mmppRedeemBtn', wrap);
    const resultEl = qs('#mmppResult', wrap);

    if(qrEnabled){
      const qrEl = qs('#mmppQr', wrap);
      if(qrEl){
        const url = window.location.href;
        const src = 'https://chart.googleapis.com/chart?cht=qr&chs=220x220&chl=' + encodeURIComponent(url);
        qrEl.innerHTML = '<img alt="QR" src="'+src+'">';
      }

      const scanBtn = qs('#mmppScanBtn', wrap);
      const scanWrap = qs('#mmppScan', wrap);
      const stopBtn = qs('#mmppStopScan', wrap);
      const scanMsg = qs('#mmppScanMsg', wrap);

      let stream = null;
      let detector = null;
      let rafId = null;

      async function startScan(){
        if(!('BarcodeDetector' in window)){
          setMsg(scanMsg, 'Scanner not supported on this device. Use the Redeem button instead.', 'bad');
          scanWrap.hidden = false;
          return;
        }
        try {
          detector = new BarcodeDetector({ formats: ['qr_code'] });
        } catch(e){
          detector = new BarcodeDetector();
        }

        const video = qs('#mmppVideo', wrap);
        scanWrap.hidden = false;
        setMsg(scanMsg, 'Point the camera at a QR code.', '');

        try {
          stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false });
          video.srcObject = stream;
          await video.play();

          const tick = async () => {
            if(!video || video.readyState < 2){
              rafId = requestAnimationFrame(tick);
              return;
            }
            try {
              const barcodes = await detector.detect(video);
              if(barcodes && barcodes.length){
                const value = barcodes[0].rawValue || '';
                if(value){
                  stopScan();
                  window.location.href = value;
                  return;
                }
              }
            } catch(e) {}
            rafId = requestAnimationFrame(tick);
          };

          rafId = requestAnimationFrame(tick);
        } catch(e){
          setMsg(scanMsg, 'Camera access was blocked. Allow camera and try again.', 'bad');
        }
      }

      function stopScan(){
        if(rafId) cancelAnimationFrame(rafId);
        rafId = null;
        const video = qs('#mmppVideo', wrap);
        if(video){
          try{ video.pause(); } catch(e){}
          video.srcObject = null;
        }
        if(stream){
          try{ stream.getTracks().forEach(t => t.stop()); } catch(e){}
        }
        stream = null;
        detector = null;
      }

      if(scanBtn){
        scanBtn.addEventListener('click', startScan);
      }
      if(stopBtn){
        stopBtn.addEventListener('click', function(){ stopScan(); scanWrap.hidden = true; });
      }
    }

    if(redeemBtn){
      redeemBtn.addEventListener('click', async function(){
        redeemBtn.disabled = true;
        setMsg(resultEl, 'Checking...', '');

        const pinEl = qs('#mmppPin', wrap);
        const pin = pinEl ? pinEl.value : '';

        if(pinRequired && !pin){
          setMsg(resultEl, 'PIN required.', 'bad');
          redeemBtn.disabled = false;
          return;
        }

        const url = (window.MMPP && MMPP.restUrl) ? (MMPP.restUrl + '/redeem') : '/wp-json/mmpp/v1/redeem';
        const res = await postJson(url, { token, pin });

        if(res.ok && res.data && res.data.ok){
          setMsg(resultEl, 'Redeemed. Pour the free pint.', 'ok');
          redeemBtn.textContent = 'Redeemed';
          redeemBtn.disabled = true;
          return;
        }

        const state = (res.data && res.data.state) ? res.data.state : 'error';
        if(state === 'already_redeemed'){
          setMsg(resultEl, 'Already redeemed. Do not pour.', 'bad');
        } else if(state === 'bad_pin'){
          setMsg(resultEl, 'Wrong PIN.', 'bad');
        } else if(state === 'not_found'){
          setMsg(resultEl, 'Not found.', 'bad');
        } else {
          setMsg(resultEl, 'Could not redeem. Try again.', 'bad');
        }

        redeemBtn.disabled = false;
      });
    }
  }

  document.addEventListener('DOMContentLoaded', initClaim);
})();
