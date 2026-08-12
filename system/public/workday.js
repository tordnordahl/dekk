document.addEventListener('DOMContentLoaded', () => {
  const modal=document.querySelector('[data-camera-modal]'),video=document.querySelector('[data-camera-video]'),status=document.querySelector('[data-camera-status]'),input=document.querySelector('[data-scan-input]');let stream=null,running=false;
  const stop=()=>{running=false;stream?.getTracks().forEach(track=>track.stop());stream=null;};
  document.querySelector('[data-camera-open]')?.addEventListener('click',()=>modal?.showModal());
  document.querySelector('[data-camera-close]')?.addEventListener('click',()=>{stop();modal?.close()});
  document.querySelector('[data-camera-start]')?.addEventListener('click',async()=>{if(!('BarcodeDetector'in window)){status.textContent='Denne nettleseren støtter ikke kameraskanning. Skriv hjulkoden i feltet i stedet.';return}try{stream=await navigator.mediaDevices.getUserMedia({video:{facingMode:{ideal:'environment'}}});video.srcObject=stream;await video.play();running=true;status.textContent='Leter etter QR-kode …';const detector=new BarcodeDetector({formats:['qr_code']});const scan=async()=>{if(!running)return;try{const codes=await detector.detect(video);if(codes[0]?.rawValue){input.value=codes[0].rawValue.toUpperCase().trim();stop();modal.close();input.form.submit();return}}catch(e){}requestAnimationFrame(scan)};scan()}catch(e){status.textContent='Kunne ikke åpne kameraet. Du kan fortsatt skrive inn hjulkoden.';}});
  modal?.addEventListener('close',stop);
});
