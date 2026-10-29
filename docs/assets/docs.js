// Agrega un botón «Copiar» a cada bloque de código. Es opcional: sin JavaScript la documentación se lee igual.
// Adds a «Copy» button to every code block. It is optional: without JavaScript the documentation reads the same.
(function () {
  if (!navigator.clipboard) {
    return;
  }

  var spanish = document.documentElement.lang === 'es';
  var labels = spanish ? { copy: 'Copiar', done: 'Copiado' } : { copy: 'Copy', done: 'Copied' };

  document.querySelectorAll('article pre').forEach(function (pre) {
    var button = document.createElement('button');
    button.type = 'button';
    button.className = 'copy';
    button.textContent = labels.copy;

    pre.classList.add('group', 'relative');
    pre.appendChild(button);

    button.addEventListener('click', function () {
      var code = pre.querySelector('code') || pre;

      navigator.clipboard.writeText(code.innerText).then(function () {
        button.textContent = labels.done;
        setTimeout(function () {
          button.textContent = labels.copy;
        }, 1500);
      });
    });
  });
})();
