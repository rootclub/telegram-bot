<?php
function _start($chatType){
	if ($chatType == 'private') {
		return "Ciao! Sono il bot del root. usa /help oppure /aiuto per vedere cosa posso fare";
	}else{
		return "Ciao a tutti! Sono il bot di questo gruppo. Usate /help oppure /aiuto per vedere cosa posso fare.";
	}
}

function _help(){
	return "Ecco cosa posso fare:
/info - Informazioni sul gruppo
/regole - Mostra le regole del gruppo
@root seguito da un messaggio per parlare con me (usa Ollama, gira sul PC di Lamberto quindi quando è spento non rispondo)
/stats - classifica delle parolacce del gruppo

Posso aiutare per raccogliere gli ordini per le cene al root, ecco i comandi:
/mangerei [pietanza] - crea nuovo ordine o sostituisce la tua pietanza nell'ordine odierno
/mangerebbe [pietanza] - inserisci ordine per un altro utente rispondendo al suo messaggio (solo admin)
/annullo - elimini la tua pietanza dall'ordine, partecipazione o ospite
/lista - mostra la lista delle pietanze di cui è composto l'ordine
/ordino - ti offri per telefonare e piazzare l'ordine presso l'asporto scelto
/ordina @utente - designi una persona per telefonare e piazzare l'ordine presso l'asporto scelto
/ritiro - ti offri per ritirare le pietanze presso l'asporto scelto
/ritira @utente - designi una persona per ritirare le pietanze presso l'asporto scelto
/elenco_asporto - mostra i locali da asporto predefiniti e permette di consultarne i menù
/asporto - per scegliere da quale locale da asporto si ordinerà il cibo
/nuovo_asporto [nome], [indirizzo], [telefono], [giorni chiusura] - aggiungi un nuovo locale da asporto
/menu - mostra il menù dell'asporto scelto per l'ordine in corso (inviato in privato)
/nuovo_menu - aggiunge o sostituisce le immagini del menù (solo admin). Puoi inviare più immagini insieme, compresse o come file. Termina con /fine (timeout 10 min)
/fine - termina l'inserimento delle immagini del menù
/elimina_asporto - elimina un locale da asporto dai predefiniti (solo admin)

Posso anche gestire eventi speciali (corsi, cene, talk):
/evento - crea un nuovo evento con descrizione, data/ora e costo (solo admin)
/partecipo - iscriviti a un evento
/partecipanti - mostra info evento e la lista dei partecipanti
/ospite [nome] - aggiungi un ospite (moglie, figli, ecc.) a un evento
/annullo - elimini la tua partecipazione o ospite
/annullo_ospite - rimuovi un ospite che hai aggiunto
/modifica_evento - modifica descrizione, data/ora o costo di un evento (solo admin)
/chiudi_evento - elimina un evento concluso (solo admin)";
}

function _info($chatID, $chatType){
	if ($chatType == 'private') {
		return "Ma che info vuoi che siamo solo io e te?";
	}else{
		$chatInfo = makeAPIRequest('getChat', ['chat_id' => $chatID]);
		$memberCount = makeAPIRequest('getChatMemberCount', ['chat_id' => $chatID]);
		return "Informazioni sul gruppo:\nNome: " . $chatInfo['result']['title'] . "\nMembri: " . $memberCount['result'];
	}
}

function _regole(){
	return "Regole del gruppo:
0. Si incomincia a contare da 0
1. Parlate di quello che vi pare eccetto:
2. Niente pseudoscienze
3. Niente complottismi
4. Non si usano messaggi vocali, i vocali sono il male assoluto. Ogni vocale verrà utilizzato per addestrare una voce artificiale per doppiare i porno LGBT+
5. Usa tab per indentare il codice, non spazi multipli, no scherzo, il codice è il tuo, fai come ti pare, però se usi gli spazi sappi che sei una brutta persona :-D ";
	
}
?>
