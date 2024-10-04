Script che è stato scritto per gestire il bot del gruppo telegram /root/bar allo scopo di raccogliere le ordinazioni dai membri del gruppo che intendano mangiare al circolo nelle serate di apertura.
Come far funzionare l'aggeggio:
1) Copia i file in una directory del tuo server web, la direcory images deve avere i diritti di scrittura
2) Crea un nuovo bot su telegram utilizzando botfather
3) Configura il file config.php immettendo il token telegram, l'url dove si trova lo script php, l'url della macchia che usi per fare girare ollama
4) Invoca lo script set_webhook.php (da browser) per indicare a telegram dove si trova lo script di controllo del bot
