var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
var pxpersec, starttm, numdays, usertmoffset, subtimer, prevtm, htimer, whichDog, zi, tlheight, globalnoanim, tlzoom;
htimer = 0;
whichDog = 0;
zi = 5;

function AjaxSubmitSP(theform, sub) {
	AjaxLoadingSP();
	var vdata = jQuery('#'+theform).serialize();
	vdata = 'action=stmjs&ajsub=submit&' + vdata;
	jQuery.post(ajax_object.ajax_url+'?sub='+sub, vdata, function(response) {
		console.log(response);
		eval(response);
	});
}

function AjaxPopSP(pg, frm) {
	AjaxLoadingSP();
	if (frm) var vdata = jQuery('#'+frm).serialize();
	else var vdata = '';
	vdata = 'action=stmjs&ajsub=page&' + vdata;
	jQuery.post(ajax_object.ajax_url+'?pg='+pg, vdata, function(response) {
		console.log(response);
		eval(response);
	});
}


function AjaxActionSP(pg, senddata) {
	var vdata = '';
	if (senddata) {
		senddata = senddata.split(' ')
		for (var i=0;i < senddata.length;i++) {
			var fld = senddata[i];
			if (fld.substr(fld.length-1, fld.length) == '_') {
				el = document.getElementsByTagName('input');
				for(j=0; j < el.length; j++) if (el[j].name.indexOf(fld) == 0) vdata += el[j].name + '=' + encodeURIComponent(document.getElementById(el[j].id).value) + '&';
				el = document.getElementsByTagName('textarea');
				for(j=0; j < el.length; j++) if (el[j].name.indexOf(fld) == 0) vdata += el[j].name + '=' + encodeURIComponent(document.getElementById(el[j].id).value) + '&';
			}
			else {
				if (!document.getElementById(fld)) alert(fld);
				vdata += fld + '=' + encodeURIComponent(document.getElementById(fld).value) + '&';
			}
		}
	}
	vdata = 'action=stmjs&ajsub=main&' + vdata;
	jQuery.post(ajax_object.ajax_url+'?a='+pg, vdata, function(response) {
		console.log(response);
		eval(response);
	});
}




function AjaxActionLoadingSP(pg, senddata, contdiv, txt) {
	if (!txt) txt = 'Loading...';
	document.getElementById(contdiv).innerHTML = '<div class="loader-bar">'+txt+'</div>';
	AjaxActionSP(pg, senddata);
}


function Loading(e) {
	if (e) {
		d = document.getElementById('loading');
		x = findPosX(e)+15;
		y = findPosY(e)+5;
		d.style.left =  x+'px';
		d.style.top =  y+'px';
		d.style.visibility = 'visible';
	}
	else {
		if (parseInt(navigator.appVersion)>3) {
			if (navigator.appName=="Netscape") {
				winW = document.documentElement.clientWidth;
				winH = window.innerHeight;
			}
			if (navigator.appName.indexOf("Microsoft")!=-1) {
				winW = document.documentElement.clientWidth;
				winH = document.documentElement.clientHeight;
			}
		}
		d = document.getElementById('loading');
		if (window.scrollY) scr = window.scrollY;
		else if (document.documentElement.scrollTop) scr = document.documentElement.scrollTop;
		else scr = 0;
		d.style.top =  parseInt(scr+winH/2)-50+ 'px';
		d.style.left =  parseInt(winW/2)-50+ 'px';
		d.style.visibility = 'visible';
	}
}


function AjaxCB(obj, val) {
	if (obj.checked) obj.value = val;
	else obj.value = 0;
}


function MarkCheckbox(dname) {
	v = document.getElementById(dname).value;
	if (v=='0') {
		document.getElementById(dname).value = 1;
		document.getElementById('cb'+dname).className = 'chbon';
	}
	else {
		document.getElementById(dname).value = 0;
		document.getElementById('cb'+dname).className = 'chb';
	}
}


function findPosX(e) {
	var posx = 0;
	if (!e) var e = window.event;
	if (e.pageX) posx = e.pageX;
	else if (e.clientX) posx = e.clientX + document.body.scrollLeft + document.documentElement.scrollLeft;
	return posx;
}

function findPosY(e) {
	var posy = 0;
	if (!e) var e = window.event;
	if (e.pageY) posy = e.pageY;
	else if (e.clientY) posy = e.clientY + document.body.scrollTop + document.documentElement.scrollTop;
	return posy;
}


function CheckAll(formobj) {
	for (i=0; i < formobj.length; i++) if (formobj.elements[i].name.substr(0,8) == 'checked_') formobj.elements[i].checked = formobj.checkall.checked;
}

function CheckAllSpecial(formobj, fldall, prefix) {
	for (i=0; i < formobj.length; i++) if (formobj.elements[i].name.substr(0,prefix.length) == prefix) formobj.elements[i].checked = document.getElementById(fldall).checked;
}

function ConfirmDelete(form, msg) {
	var godel = window.confirm(msg);
	if (godel) {
		form.elements['suredelete'].value = 1;
		form.submit();
	}
}

function popup( url, winname, width, height ) {
	if (winname == "") winname = "popup";
	if (width == "") width = "400";
	if (height == "") height = "300";
	var top = (screen.height) / 2 - (height / 2);
	var left = (screen.width) / 2 - (width / 2);
	var win_arg = "scrollbars=yes,status=yes,resizable=yes,location=no,toolbar=no,width=" + width + ",height=" + height + ",top=" + top + ",left=" + left;
	window.open(url,winname,win_arg);
}


function ShowBasket() {
	if (htimer) clearTimeout(htimer);
	setTimeout('SlideBasket(1)', 0);
}

function HideBasket() {
	htimer = setTimeout('SlideBasket(0)', 500);
}


function SlideBasket(sdir) {
	obj = document.getElementById('basketbox');
	if (sdir) {
		r = parseInt(obj.style.right)+10;
		if (r >= 0) return ;
	}
	else {
		r = parseInt(obj.style.right)-10;
		if (r < -225) return ;
	}
	obj.style.right = r+'px';
	document.getElementById('basket').style.right = (225+r)+'px';
	setTimeout('SlideBasket('+sdir+')', 10);
}



function AjaxLoadingSP() {
	document.getElementById('dim').style.display = 'block';
	if (parseInt(navigator.appVersion)>3) {
		if (navigator.appName=="Netscape") {
			winW = document.documentElement.clientWidth;
			winH = window.innerHeight;
		}
		if (navigator.appName.indexOf("Microsoft")!=-1) {
			winW = document.documentElement.clientWidth;
			winH = document.documentElement.clientHeight;
		}
	}
	d = document.getElementById('loading');
	if (window.scrollY) scr = window.scrollY;
	else if (document.documentElement.scrollTop) scr = document.documentElement.scrollTop;
	else scr = 0;
	if (!winH) winH = 500;
	if (!winW) winW = 1000;
	d.style.top =  parseInt(scr+winH/2)-50+ 'px';
	d.style.left =  parseInt(winW/2)-50+ 'px';
	d.style.visibility = 'visible';
}

function AjaxLoadedSP() {
	document.getElementById('dim').style.display = 'none';
	document.getElementById('loading').style.visibility = 'hidden';
}


function ClearErr() {
	jQuery('#errbox').html('');
}
function ShowSubBox(sub) {
	ClearErr();
	document.getElementById('subboxfacebook').style.display='none';
	document.getElementById('subboxtwitter').style.display='none';
	document.getElementById('subboxlinkedin').style.display='none';
	document.getElementById('subboxtumblr').style.display='none';
	document.getElementById('subbox'+sub).style.display='block';
	if (sub) document.getElementById('prefbox').style.display='block';
	else document.getElementById('prefbox').style.display='none';
}


function GetNumVars() {
	maxnum = 0;
	el = document.getElementsByTagName('span');
	for(j=0; j < el.length; j++) if (el[j].id.indexOf('varnum_') == 0) {
		num = parseInt(el[j].id.replace('varnum_', ''));
		if (num > maxnum) maxnum = num;
	}
	return maxnum;
}

function AddPostSchedule() {
	var maxnum = 0;
	el = document.getElementsByTagName('span');
	for(j=0; j < el.length; j++) if (el[j].id.indexOf('schnum_') == 0) {
		num = parseInt(el[j].id.replace('schnum_', ''));
		if (num > maxnum) maxnum = num;
	}
	maxnum++;
	numvars = GetNumVars();
	AjaxActionSP('addpostschedule&num='+maxnum+'&numvars='+numvars);
}


function AddPostScheduleExt() {
	var maxnum = 0;
	el = document.getElementsByTagName('span');
	for(j=0; j < el.length; j++) if (el[j].id.indexOf('schnum_') == 0) {
		num = parseInt(el[j].id.replace('schnum_', ''));
		if (num > maxnum) maxnum = num;
	}
	maxnum++;
	AjaxActionSP('addpostschedule&ext=1&num='+maxnum);
}


function UpdateVarsSels(numvars) {
	el = document.getElementsByTagName('select');
	for(j=0; j < el.length; j++) if (el[j].name.indexOf('numvar_') == 0) {
		val = el[j].value;
		while (el[j].options.length) el[j].remove(0);
		var option = document.createElement('option');
		option.value = 0;
		option.text = 'ORIGINAL';
		el[j].add(option);
		for (i=1; i<=numvars; i++) {
			var option = document.createElement('option');
			option.value = i;
			option.text = 'Variation '+i;
			el[j].add(option);
		}
		var option = document.createElement('option');
		option.value = 200;
		option.text = '[ROTATE]';
		el[j].add(option);
		var option = document.createElement('option');
		option.value = 201;
		option.text = '[RANDOMIZE]';
		el[j].add(option);
		el[j].value = val;
	}
}

function AddVariation() {
	numvars = GetNumVars();
	numvars++;
	AjaxActionSP('addpostvar&numvars='+numvars);
	UpdateVarsSels(numvars);
}

function RenumSchedules() {
	i = 1;
	el = document.getElementsByTagName('span');
	for(j=0; j < el.length; j++) if (el[j].id.indexOf('schnum_') == 0) {
		num = parseInt(el[j].id.replace('schnum_', ''));
		if (num == i) {
			i++;
			continue;
		}
		el[j].id = 'schnum_'+i;
		el[j].innerHTML = i;
		if (!document.getElementById('intnum_'+num)) continue;
		document.getElementById('intnum_'+num).name = 'intnum_'+i;
		document.getElementById('intnum_'+num).id = 'intnum_'+i;
		document.getElementById('inttype_'+num).name = 'inttype_'+i;
		document.getElementById('inttype_'+num).id = 'inttype_'+i;
		document.getElementById('dorepeat_'+num).name = 'dorepeat_'+i;
		document.getElementById('dorepeat_'+num).id = 'dorepeat_'+i;
		document.getElementById('repnum_'+num).name = 'repnum_'+i;
		document.getElementById('repnum_'+num).id = 'repnum_'+i;
		document.getElementById('reptype_'+num).name = 'reptype_'+i;
		document.getElementById('reptype_'+num).id = 'reptype_'+i;
		document.getElementById('repcount_'+num).name = 'repcount_'+i;
		document.getElementById('repcount_'+num).id = 'repcount_'+i;
		if (document.getElementById('numvar_'+num)) {
			document.getElementById('numvar_'+num).name = 'numvar_'+i;
			document.getElementById('numvar_'+num).id = 'numvar_'+i;
		}
		document.getElementById('accountid_'+num).name = 'accountid_'+i;
		document.getElementById('accountid_'+num).id = 'accountid_'+i;
		i++;
	}
}

function RemoveSchedule(obj) {
	obj.parentElement.removeChild(obj);
	RenumSchedules();
}

function RenumVars() {
	i = 1;
	el = document.getElementsByTagName('span');
	for(j=0; j < el.length; j++) if (el[j].id.indexOf('varnum_') == 0) {
		num = parseInt(el[j].id.replace('varnum_', ''));
		if (num == i) {
			i++;
			continue;
		}
		el[j].id = 'varnum_'+i;
		el[j].innerHTML = i;
		if (!document.getElementById('title_'+num)) continue;
		document.getElementById('title_'+num).name = 'title_'+i;
		document.getElementById('title_'+num).id = 'title_'+i;
		document.getElementById('content_'+num).name = 'content_'+i;
		document.getElementById('content_'+num).id = 'content_'+i;
		i++;
	}
}

function RemoveVariation(obj) {
	obj.parentElement.removeChild(obj);
	RenumVars();
	numvars = GetNumVars();
	UpdateVarsSels(numvars);
}


function SelWPImage(num) {
	var meta_image_frame;
	meta_image_frame = wp.media.frames.meta_image_frame = wp.media({
            title: 'Select Image for Variation ' + num,
            button: { text:  'Select' },
            library: { type: 'image' }
    });
	meta_image_frame.on('select', function(){
		var media_attachment = meta_image_frame.state().get('selection').first().toJSON();
		url = media_attachment.url;
		if (url) {
			document.getElementById('imgurl_'+num).value = media_attachment.url;
			document.getElementById('previewbox_'+num).innerHTML = "<img src='"+url+"' style='max-width: 140px; max-height: 140px;' />";
		}
    });
	meta_image_frame.open();
}

function ShowDiv(divname) {
	div = document.getElementById(divname);
	jQuery('#'+divname).fadeIn('fast');
	//div.style.display = 'block';
	subtimer = setTimeout('HideSub()', 1000);
}

function HideSub() {
	jQuery('#extsub').fadeOut('slow');
}

function ShowDivAtPos(el, divname, offsetX, offsetY) {
	offs = getOffset(el);
	if (!offsetX) offsetX = 0;
	if (!offsetY) offsetY = 0;
	div = document.getElementById(divname);
	x = offs.left;
	y = offs.top;
	div.style.left = x+offsetX+'px';
	div.style.top = y+offsetY+'px';
	div.style.visibility = 'visible';
}


function getOffset(el) {
    var _x = 0;
    var _y = 0;
    while (el && !isNaN(el.offsetLeft) && !isNaN(el.offsetTop)) {
        _x += el.offsetLeft - el.scrollLeft;
        _y += el.offsetTop - el.scrollTop;
        el = el.offsetParent;
    }
    return {top: _y, left: _x};
}

Date.prototype.stdTimezoneOffset = function() {
    var jan = new Date(this.getFullYear(), 0, 1);
    var jul = new Date(this.getFullYear(), 6, 1);
    return Math.max(jan.getTimezoneOffset(), jul.getTimezoneOffset());
}

Date.prototype.dst = function() {
    return this.getTimezoneOffset() < this.stdTimezoneOffset();
}

function FormatTime(unix_timestamp, adddate, delim) {
	if (!delim) delim = '<br />';
	date = new Date(unix_timestamp*1000);
	if (date.dst()) { //daylight saving time
		unix_timestamp -= 3600;
		date = new Date(unix_timestamp*1000);
	}
	formattedTime = '';
	if (adddate) {
		//year = date.getFullYear();
		month = months[date.getMonth()];
		day = date.getDate();
		if (day < 10) day = '0' + day;
		formattedTime = month + ' ' + day + delim;// + ', ' + year + delim;
	}
	hours = date.getHours();
	if (hours < 10) hours = '0' + hours;
	minutes = '0' + date.getMinutes();
	seconds = '0' + date.getSeconds();
	formattedTime = formattedTime + hours + ':' + minutes.substr(minutes.length-2);// + ':' + seconds.substr(seconds.length-2);
	return formattedTime;
}


function ToTop() {
	zi++;
	whichDog.style.zIndex = zi;
}


function ddInit(e, obj) {
	whichDog = obj;
	ToTop();
	dragid = whichDog.id.replace('timel_', '');
	dragptsobj = document.getElementById('ptimeshow_'+dragid);
	e = e || window.event;
	offsetx = findPosX(e);
	offsety = findPosY(e);
	nowX = parseInt(whichDog.style.left);
	nowY = parseInt(whichDog.style.top);
	document.onmousemove = dd;
	document.onmouseup = ddStop;
	document.onselectstart = function () { return false; };
	whichDog.ondragstart = function() { return false; }; 
	return false;
}

function dd(e) {
	if (!whichDog) return false;
	e = e || window.event;
	t = nowY+findPosY(e)-offsety;
	whichDog.style.left = (nowX+findPosX(e)-offsetx)+'px';
	document.getElementById('linetime').style.display = 'none';
	if ((t >= 0) && (t<=1440*numdays)) {
		whichDog.style.top = t+'px';
		tm = starttm + (3600*t/pxpersec);
		dragptsobj.innerHTML = FormatTime(tm)+'<span></span>';
		InPreferred(whichDog);
	}
	if (Math.abs(findPosY(e)-offsety) > 0) {
		document.getElementById('savebutbox').style.display='inline';
		document.getElementById('savelnk').style.display='inline';
	}
	return false;
}


function ddStop() {
	document.onmousemove = null;
	document.onmouseup = null;
	document.onselectstart = null;
	whichDog.ondragstart = null; 
	whichDog = null;
}

function RemoveDiv(did) {
	obj = document.getElementById(did);
	obj.parentElement.removeChild(obj);
}

function ShowLinetime(e, stat) {
	obj = document.getElementById('linetime');
	if (stat) {
		LinetimePos(e);
		obj.style.display = 'block';
	}
	else obj.style.display = 'none';
}

function getPosition(element) {
    var xPosition = 0;
    var yPosition = 0;
    while(element) {
        xPosition += (element.offsetLeft - element.scrollLeft + element.clientLeft);
        yPosition += (element.offsetTop - element.scrollTop + element.clientTop);
        element = element.offsetParent;
    }
    return { x: xPosition, y: yPosition };
}

function LinetimePos(e) {
	obj = document.getElementById('linetime');
	obj.style.display = 'block';
	offs = getPosition(document.getElementById('timelinebox')).y;
	t = findPosY(e)-offs;
	obj.style.top = t+'px';
	tm = starttm + parseInt(3600*t/pxpersec);
	document.getElementById('lttime').value = tm;
	obj.innerHTML = FormatTime(tm, 1)+'<span></span>';
}


function TimelineZoomTo(zlev) {
	tozoom = tlzoom - zlev;
	numz = Math.abs(tozoom);
	for (i=0; i<numz; i++) {
		if (tozoom > 0) TimelineZoomOut(1, 1);
		else TimelineZoomIn(1, 1);
	}
	AjaxActionSP('storezoom&z='+tlzoom);
}

function TimelineZoomIn(nosave, noanimate) {
	if (!nosave) nosave = 0;
	if (!noanimate) noanimate = 0;
	if (noanimate) doanimate = 0;
	else doanimate = 1;
	if (globalnoanim) doanimate = 0;
	if (numdays > 3) doanimate = 0;
	if (tlzoom > 10) return ;
	stmurl = document.getElementById('stmurl').value;
	h = parseInt(document.getElementById('timeline').style.height);
	h *= 2;
	document.getElementById('timeline').style.height = h + 'px';
	document.getElementById('line').style.height = h + 'px';
	el = document.getElementById('timeline').getElementsByTagName('div');
	for(j=0; j < el.length; j++) if (el[j].id.indexOf('pref_') == 0) {
		t = parseInt(el[j].style.top);
		t *= 2;
		if (doanimate) jQuery('#'+el[j].id).animate({top:t}, 100);
		else el[j].style.top = t + 'px';
		h = parseInt(el[j].style.height);
		h *= 2;
		if (doanimate) jQuery('#'+el[j].id).animate({height:h}, 100);
		else el[j].style.height = h + 'px';
	}
	for(j=0; j < el.length; j++) if (el[j].id.indexOf('date_') == 0) {
		t = parseInt(el[j].style.top);
		t *= 2;
		if (doanimate) jQuery('#'+el[j].id).animate({top:t}, 300);
		else el[j].style.top = t + 'px';
	}
	for(j=0; j < el.length; j++) if (el[j].id.indexOf('timel_') == 0) {
		t = parseInt(el[j].style.top);
		t *= 2;
		if (doanimate) jQuery('#'+el[j].id).animate({top:t}, 300);
		else el[j].style.top = t + 'px';
	}
	el = document.getElementById('timeline').getElementsByTagName('span');
	for(j=0; j < el.length; j++) if (el[j].id.indexOf('htick_') == 0) {
		t = parseInt(el[j].style.top);
		t *= 2;
		if (doanimate) jQuery('#'+el[j].id).animate({top:t}, 300);
		else el[j].style.top = t + 'px';
	}
	pxpersec *= 2;
	if (tlzoom == 1) {
		document.getElementById('zoimage').src = stmurl + '/images/zoom_out.png';
		document.getElementById('zoimage2').src = stmurl + '/images/zoom_out.png';
	}
	tlzoom++;
	if (tlzoom == 10) {
		document.getElementById('ziimage').src = stmurl + '/images/zoom_in_dis.png';
		document.getElementById('ziimage2').src = stmurl + '/images/zoom_in_dis.png';
	}
	if (!nosave) AjaxActionSP('storezoom&z='+tlzoom);
}

function TimelineZoomOut(nosave, noanimate) {
	if (!nosave) nosave = 0;
	if (!noanimate) noanimate = 0;
	if (noanimate) doanimate = 0;
	else doanimate = 1;
	if (globalnoanim) doanimate = 0;
	if (numdays > 3) doanimate = 0;
	if (tlzoom < 2) return ;
	stmurl = document.getElementById('stmurl').value;
	h = parseInt(document.getElementById('timeline').style.height);
	h /= 2;
	document.getElementById('timeline').style.height = h + 'px';
	document.getElementById('line').style.height = h + 'px';
	el = document.getElementById('timeline').getElementsByTagName('div');
	for(j=0; j < el.length; j++) if (el[j].id.indexOf('pref_') == 0) {
		t = parseInt(el[j].style.top);
		t /= 2;
		if (doanimate) jQuery('#'+el[j].id).animate({top:t}, 100);
		else el[j].style.top = t + 'px';
		h = parseInt(el[j].style.height);
		h /= 2;
		if (doanimate) jQuery('#'+el[j].id).animate({height:h}, 100);
		else el[j].style.height = h + 'px';
	}
	for(j=0; j < el.length; j++) if (el[j].id.indexOf('date_') == 0) {
		t = parseInt(el[j].style.top);
		t /= 2;
		if (doanimate) jQuery('#'+el[j].id).animate({top:t}, 300);
		else el[j].style.top = t + 'px';
	}
	for(j=0; j < el.length; j++) if (el[j].id.indexOf('timel_') == 0) {
		t = parseInt(el[j].style.top);
		t /= 2;
		if (doanimate) jQuery('#'+el[j].id).animate({top:t}, 300);
		else el[j].style.top = t + 'px';
	}
	el = document.getElementById('timeline').getElementsByTagName('span');
	for(j=0; j < el.length; j++) if (el[j].id.indexOf('htick_') == 0) {
		t = parseInt(el[j].style.top);
		t /= 2;
		if (doanimate) jQuery('#'+el[j].id).animate({top:t}, 300);
		else el[j].style.top = t + 'px';
	}
	pxpersec /= 2;
	if (tlzoom == 10) {
		document.getElementById('ziimage').src = stmurl + '/images/zoom_in.png';
		document.getElementById('ziimage2').src = stmurl + '/images/zoom_in.png';
	}
	tlzoom--;
	if (tlzoom == 1) {
		document.getElementById('zoimage').src = stmurl + '/images/zoom_out_dis.png';
		document.getElementById('zoimage2').src = stmurl + '/images/zoom_out_dis.png';
	}
	if (!nosave) AjaxActionSP('storezoom&z='+tlzoom);
}



function sortNumber(a, b) {
	a = parseInt(a);
	b = parseInt(b);
	return a-b;
}

function DayHeight() {
	zdif = tlzoom-5;
	h = 1440;
	if (zdif > 0) h = 1440*Math.pow(2, zdif);
	if (zdif < 0) {
		zdif = -zdif;
		h = 1440/Math.pow(2, zdif);
	}
	return h;
}

function GetPrefProps() {
	el = document.getElementById('timeline').getElementsByTagName('div');
	for(j=0; j < el.length; j++) if (el[j].id.indexOf('pref_') == 0) {
		pt = parseInt(el[j].style.top);
		ph = parseInt(el[j].style.height);
		break;
	}
	return { t: pt, h: ph };
}

function DistrEvenlyPerDay(prefonly, perday) {
	dh = DayHeight();
	for (i=1; i<=numdays; i++) {
		numels = 0;
		arr = new Array();
		el = document.getElementById('timeline').getElementsByTagName('div');
		for(j=0; j < el.length; j++) if (el[j].id.indexOf('timel_') == 0) {
			t = parseInt(el[j].style.top);
			if ((t < (i-1)*dh) || (t > i*dh)) continue;
			arr[numels] = el[j].style.top+'-'+el[j].id;
			numels++;
		}
		if (prefonly) {
			prefprops = GetPrefProps();
			pxperel = parseInt(prefprops.h/numels);
			t = parseInt(((i-1)*dh) + prefprops.t + pxperel/2);
		}
		else {
			pxperel = parseInt(dh/numels);
			t = parseInt(((i-1)*dh) + pxperel/2);
		}
		arr.sort(sortNumber);
		for (j=0; j<numels; j++) {
			stub = arr[j].split('-');
			fullid = stub[1];
			nid = fullid.replace('timel_', '');
			if (globalnoanim) document.getElementById(fullid).style.top = t + 'px';
			else jQuery('#'+fullid).animate({top:t}, 200);
			tm = starttm + (3600*t/pxpersec);
			document.getElementById('ptimeshow_'+nid).innerHTML = FormatTime(tm)+'<span></span>';
			t += pxperel;
		}
	}
	document.getElementById('savebutbox').style.display='inline';
	document.getElementById('savelnk').style.display='inline';
	if (globalnoanim) InPreferredAll();
	else setTimeout('InPreferredAll()', 400);
}


function DistrEvenly(prefonly, perday) {
	if (perday) return DistrEvenlyPerDay(prefonly, perday);
	if (prefonly) {
		dh = DayHeight();
		prefprops = GetPrefProps();
		pt = prefprops.t;
		ph = prefprops.h;
		h = ph*numdays;
		numels = 0;
		arr = new Array();
		counter = 0;
		el = document.getElementById('timeline').getElementsByTagName('div');
		for(j=0; j < el.length; j++) if (el[j].id.indexOf('timel_') == 0) {
			numels++;
			arr[counter] = el[j].style.top+'-'+el[j].id;
			counter++;
		}
		pxperel = parseInt(h/numels);
		arr.sort(sortNumber);
		numday = 1;
		t = parseInt(pt + pxperel/2);
		for (i=0; i<counter; i++) {
			stub = arr[i].split('-');
			fullid = stub[1];
			nid = fullid.replace('timel_', '');
			if (globalnoanim) document.getElementById(fullid).style.top = t + 'px';
			else jQuery('#'+fullid).animate({top:t}, 200);
			tm = starttm + (3600*t/pxpersec);
			document.getElementById('ptimeshow_'+nid).innerHTML = FormatTime(tm)+'<span></span>';
			t += pxperel;
			stubh = ((numday-1)*dh) + pt+ph;
			if (t > stubh) {
				numday++;
				pxover = t - stubh;
				t = ((numday-1)*dh) + pt+pxover;
			}
		}
		document.getElementById('savebutbox').style.display='inline';
		document.getElementById('savelnk').style.display='inline';
		if (globalnoanim) InPreferredAll();
		else setTimeout('InPreferredAll()', 400);
		return;
	}
	h = parseInt(document.getElementById('timeline').style.height);
	numels = 0;
	arr = new Array();
	counter = 0;
	el = document.getElementById('timeline').getElementsByTagName('div');
	for(j=0; j < el.length; j++) if (el[j].id.indexOf('timel_') == 0) {
		numels++;
		arr[counter] = el[j].style.top+'-'+el[j].id;
		counter++;
	}
	pxperel = h/(numels);
	arr.sort(sortNumber);
	t = parseInt(pxperel/2);
	for (i=0; i<counter; i++) {
		stub = arr[i].split('-');
		fullid = stub[1];
		nid = fullid.replace('timel_', '');
		if (globalnoanim) document.getElementById(fullid).style.top = t + 'px';
		else jQuery('#'+fullid).animate({top:t}, 200);
		tm = starttm + (3600*t/pxpersec);
		document.getElementById('ptimeshow_'+nid).innerHTML = FormatTime(tm)+'<span></span>';
		t += pxperel;
	}
	document.getElementById('savebutbox').style.display='inline';
	document.getElementById('savelnk').style.display='inline';
	if (globalnoanim) InPreferredAll();
	else setTimeout('InPreferredAll()', 400);
}

function DistrRandom(dtype, deviation, intfrom, intto, prefonly) {
	numels = 0;
	arr = new Array();
	counter = 0;
	el = document.getElementById('timeline').getElementsByTagName('div');
	for(j=0; j < el.length; j++) if (el[j].id.indexOf('timel_') == 0) {
		numels++;
		arr[counter] = el[j].style.top+'-'+el[j].id;
		counter++;
	}
	arr.sort(sortNumber);
	if (dtype==2) {
		if (prefonly) {
			dh = DayHeight();
			prefprops = GetPrefProps();
			pt = prefprops.t;
			ph = prefprops.h;
			t = pt;
		}
		else t = 0;
		dif = intto-intfrom;
		numday = 1;
		for (i=0; i<counter; i++) {
			stub = arr[i].split('-');
			fullid = stub[1];
			nid = fullid.replace('timel_', '');
			if (globalnoanim) document.getElementById(fullid).style.top = t + 'px';
			else jQuery('#'+fullid).animate({top:t}, 200);
			tm = starttm + (3600*t/pxpersec);
			document.getElementById('ptimeshow_'+nid).innerHTML = FormatTime(tm)+'<span></span>';
			int = intfrom + dif*(Math.random()); //in minutes
			t += parseInt(pxpersec*(int/60));
			if (prefonly) {
				stubh = ((numday-1)*dh) + pt+ph;
				if (t > stubh) {
					numday++;
					pxover = t - stubh;
					t = ((numday-1)*dh) + pt+pxover;
				}
			}
		}
	}
	if (dtype==1) {
		if (prefonly) {
			dh = DayHeight();
			prefprops = GetPrefProps();
			pt = prefprops.t;
			ph = prefprops.h;
			h = numdays*ph;
			int = parseInt((h/numels)/3);
			remainh = h - int;
			t = pt + int;
		}
		else {
			h = parseInt(document.getElementById('timeline').style.height);
			t = parseInt((h/numels)/3);
			remainh = h - t;
		}
		numday = 1;
		for (i=0; i<counter; i++) {
			stub = arr[i].split('-');
			fullid = stub[1];
			nid = fullid.replace('timel_', '');
			if (globalnoanim) document.getElementById(fullid).style.top = t + 'px';
			else jQuery('#'+fullid).animate({top:t}, 200);
			tm = starttm + (3600*t/pxpersec);
			document.getElementById('ptimeshow_'+nid).innerHTML = FormatTime(tm)+'<span></span>';
			remainel = numels-i;
			pxperel = remainh/remainel;
			intfrom = pxperel - (deviation*pxperel)/100;
			intto = pxperel + (deviation*pxperel)/100;
			dif = intto - intfrom;
			int = parseInt(intfrom + dif*(Math.random())); //in minutes
			t += int;
			remainh -= int;
			if (prefonly) {
				stubh = ((numday-1)*dh) + pt+ph;
				if (t > stubh) {
					numday++;
					pxover = t - stubh;
					t = ((numday-1)*dh) + pt+pxover;
				}
			}
		}
	}
	document.getElementById('savebutbox').style.display='inline';
	document.getElementById('savelnk').style.display='inline';
	if (globalnoanim) InPreferredAll();
	else setTimeout('InPreferredAll()', 400);
}

function AddToTimeline() {
	q = 'addtotimeline&accountid='+document.getElementById('accountid').value+'&utm='+document.getElementById('lttime').value;
	q += '&starttm='+document.getElementById('starttm').value+'&endtm='+document.getElementById('endtm').value;
	q += '&pxpersec='+pxpersec;
	AjaxPopSP(q);
}

function ShowOptDiv(num, numduvs) {
	for (i=1; i<=numduvs; i++) document.getElementById('opt'+i).style.display='none';
	document.getElementById('opt'+num).style.display='block';
	if (num==2) document.getElementById('meurl').style.display='block';
	else document.getElementById('meurl').style.display='none';
}


function InPreferred(obj) {
	oid = obj.id.replace('timel_', 'ptimeshow_');
	tobj = document.getElementById(oid);
	tobj.className = 'tm';
	t = parseInt(obj.style.top);
	el = document.getElementById('timeline').getElementsByTagName('div');
	for(j=0; j < el.length; j++) if (el[j].id.indexOf('pref_') == 0) {
		pt = parseInt(el[j].style.top);
		pb = pt + parseInt(el[j].style.height);
		if ((t >= pt) && (t <= pb)) tobj.className = 'tmg';
	}
}

function InPreferredAll() {
	els = document.getElementById('timeline').getElementsByTagName('div');
	for(i=0; i < els.length; i++)
		if (els[i].id.indexOf('timel_') == 0) InPreferred(els[i]);
}

function ResizeTLBox() {
	if (tlheight > 0) return;
	obj = document.getElementById('timelinebox');
	h = document.body.clientHeight;
	tlbox = Math.abs(getPosition(obj).y) - obj.scrollTop;
	tlbox = Math.abs(tlbox);
	h = h-tlbox;
	obj.style.height = h+'px';
}

function ShowHideTools() {
	obj = document.getElementById('toptools');
	obj2 = document.getElementById('expanddiv');
	if (obj.style.display == 'none') {
		obj.style.display = 'block';
		obj2.style.display = 'none';
	}
	else {
		obj.style.display = 'none';
		obj2.style.display = 'block';
	}
	ResizeTLBox();
}

function RefreshTotal() {
	total = 0;
	el = document.getElementById('urlsbox').getElementsByTagName('span');
	for(j=0; j < el.length; j++) if (el[j].id.indexOf('clicks_') == 0) {
		cont = el[j].innerHTML;
		if (cont=='n/a') continue;
		if (cont.indexOf('/')==-1) continue;
		if (el[j].innerHTML.indexOf('refreshanim.gif') > 1) continue;
		stub = cont.split('/');
		total += parseInt(stub[0]);
	}
	document.getElementById('totclicks').innerHTML = total;
}

function RefreshNext() {
	RefreshTotal();
	el = document.getElementById('urlsbox').getElementsByTagName('span');
	for(j=0; j < el.length; j++) if (el[j].id.indexOf('clicks_') == 0) {
		if (el[j].innerHTML.indexOf('refreshanim.gif') > 1) {
			postid = el[j].id.replace('clicks_', '');
			AjaxActionSP('refreshclicks&plid='+postid);
			return;
		}
	}
}

function RefreshClicks(imgsrc) {
	img = '<img src="'+imgsrc+'" width=16 height=16 title="retrieving click stats" />';
	el = document.getElementById('urlsbox').getElementsByTagName('span');
	for(j=0; j < el.length; j++) if (el[j].id.indexOf('clicks_') == 0) {
		if (el[j].innerHTML!='n/a') el[j].innerHTML = img;
	}
	RefreshNext();
}

function PreviewImg() {
	v = document.getElementById('imgurl').value;
	if (v) img = '<img src="'+v+'" style="max-width: 140px; max-height: 140px;" />';
	else img = '';
	document.getElementById('previewbox').innerHTML = img;
}

function TLNumPosts() {
	num = 0;
	el = document.getElementById('timeline').getElementsByTagName('div');
	for(j=0; j < el.length; j++) if (el[j].id.indexOf('timel_') == 0) num++;
	document.getElementById('numposts').innerHTML = num;
}

function NavDay(ndir) {
	dh = DayHeight();
	st = document.getElementById('timelinebox').scrollTop;
	curday = parseInt(st/dh);
	if (ndir == '+1') curday++;
	else if (ndir == '-1') {
		if (st/dh-curday < 0.1) curday--;
	}
	else curday = ndir;
	document.getElementById('timelinebox').scrollTop = curday*dh;
	TLBScroll();
}


function TLBScroll() {
	stmurl = document.getElementById('stmurl').value;
	st = parseInt(document.getElementById('timelinebox').scrollTop);
	dh = DayHeight();
	curday = parseInt(st/dh);
	document.getElementById('curday').value = curday+1;
	if (st == 0) {
		document.getElementById('prevday').src = stmurl + '/images/leftd.png';
		document.getElementById('prevday2').src = stmurl + '/images/leftd.png';
	}
	else {
		document.getElementById('prevday').src = stmurl + '/images/left.png';
		document.getElementById('prevday2').src = stmurl + '/images/left.png';
	}
	maxscroll = document.getElementById('timelinebox').scrollHeight - document.getElementById('timelinebox').clientHeight;
	if (maxscroll-st < 5) {
		document.getElementById('nextday').src = stmurl + '/images/rightd.png';
		document.getElementById('nextday2').src = stmurl + '/images/rightd.png';
	}
	else {
		document.getElementById('nextday').src = stmurl + '/images/right.png';
		document.getElementById('nextday2').src = stmurl + '/images/right.png';
	}
}

function HideErr() {
	document.getElementById('poperr').style.display='none';
}