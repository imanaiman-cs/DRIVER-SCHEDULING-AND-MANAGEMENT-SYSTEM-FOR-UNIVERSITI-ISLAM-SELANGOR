/* UIS Theme — dark mode toggle. Load at the END of <head> (before CSS paints) in includes/header.php. */
(function(){
  var KEY='uisTheme',root=document.documentElement;
  var saved=localStorage.getItem(KEY);
  if(saved==='dark'||(!saved&&window.matchMedia('(prefers-color-scheme: dark)').matches)) root.setAttribute('data-theme','dark');
  function set(m){root.setAttribute('data-theme',m);localStorage.setItem(KEY,m);sync();}
  function sync(){document.querySelectorAll('.uis-theme-toggle i').forEach(function(i){i.className='fa-solid '+(root.getAttribute('data-theme')==='dark'?'fa-sun':'fa-moon');});}
  document.addEventListener('DOMContentLoaded',function(){
    function make(){var b=document.createElement('button');b.type='button';b.className='uis-theme-toggle';b.title='Toggle dark mode';b.setAttribute('aria-label','Toggle dark mode');b.innerHTML='<i></i>';
      b.onclick=function(){set(root.getAttribute('data-theme')==='dark'?'light':'dark');};return b;}
    if(document.querySelector('.uis-theme-toggle'))return sync();
    // desktop: in the right controls of the header (before the clock); phone: in the top bar before the log out button
    var right=document.querySelector('.desktop-header .d-flex.flex-shrink-0');
    if(right)right.insertBefore(make(),right.firstChild);
    var bar=document.querySelector('.topbar'),out=bar&&bar.querySelector('.topbar-logout');
    if(bar&&out)bar.insertBefore(make(),out);
    sync();
  });
})();
