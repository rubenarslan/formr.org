#    qplot_on_normal = function(xintercept,  ylab = "Normal distribution", xlab = '' , colour = "blue") {
#        z = rnorm(1000)
#        qplot(z, geom = "blank") +
#        stat_function(fun=dnorm, aes(x = z)) + 
#        geom_vline(xintercept= xintercept, colour= colour) +
#        ylab(ylab) + 
#        xlab(xlab)
#    }
#    
#    plot_value_on_other_data = function(variable,title, xlab, ylab = 'Häufigkeit dieses Wertes in unserer Stichprobe',standardize=T, wave2=T) {
#    tryCatch({
#    	wavecolour = wave1colour
#    	if(wave2) wavecolour = wave2colour
#    
#    	if(standardize) {
#    	stand = scale(si[,variable])[,1]
#    	} else 
#    	{
#    		stand = si[,variable]
#    	}
#    	zvalue = stand[which(si$id==id)]
#    	stopifnot(!is.na(zvalue))
#    	if(!is.na(zvalue)) {
#        
#    		if(standardize) {
#        if(zvalue>3) zvalue=3
#        if(zvalue<(-3)) zvalue=-3  
#    		stand = stand + rnorm(length(stand),sd=0.2)
#    
#        }
#    	normal = data.frame(werte = seq(-3,3,length=2000),haeufigkeit = dnorm(seq(-3,3,length=2000),mean=0, sd=1))
#    		plot=
#    			ggplot()+
#    			geom_bar(data=as.data.frame(stand),aes(x=stand,y=..density..),alpha=0.4,fill=wavecolour)+
#    			geom_vline(colour=wavecolour,xintercept=zvalue,size=1.2)+
#    			xlab(xlab)+
#        	ylab(ylab)+
#    			labs(title=title) + 
#    			scale_y_continuous(breaks=c(0,0.1,0.2,0.3,0.4),labels=c('0%','10%','20%','30%','40%'))
#    		if(standardize)
#    			{
#    				plot + geom_line(data=normal,aes(x=werte,y=haeufigkeit),alpha=0.8)+
#      scale_x_continuous(breaks=c(-2,-1,0,1,2),labels=c('stark unter-\ndurchschnitlich','unterdurch-\nschnittlich','Durchschnitt','überdurch-\nschnittlich','stark über-\ndurchschnittlich'))
#    
#    		} else {
#    			plot
#    		}
#    
#    	}
#    }, error = function(e) {stop(simpleError('Dieser Wert fehlt bei Ihnen.'))})
#    }
#    plot_association = function(xvar, yvar, title, xlab, ylab, wave1=T)
#    {
#    	si$xvar = scale(si[,xvar])
#    	si$yvar = scale(si[,yvar])
#    	colo = wave2colour
#    	if(wave1) colo = wave1colour
#    	ggplot(data=si,aes(x=xvar,y=yvar,alpha=Wer)) + 
#    		geom_jitter(aes(size=Wer),colour=colo) + 
#    		scale_alpha_discrete(range = c(0.4, 1))+
#    		scale_size_discrete(range = c(2, 4))+
#    		geom_smooth(colour=colo,method="lm",se=F) + 
#    		scale_x_continuous(xlab,breaks=c(-2,-1,0,1,2),labels=c('--','-','ø','+','++')) + 
#    		scale_y_continuous(ylab,breaks=c(-2,-1,0,1,2),labels=c('--','-','ø','+','++')) + 
#    		labs(title=title)
#    }
#    
#    plot_diary_association = function(xvar, yvar, title, xlab, ylab)
#    {
#    tryCatch({
#    	ddd = diary1[,c('id',xvar,yvar)]
#    	ddd2 = diary2[,c('id',paste0(xvar,'_w2'),paste0(yvar,'_w2'))]
#    	names(ddd2) = names(ddd) = c('id','xvar','yvar')
#    	ddd$Welle = 1;	ddd2$Welle = 2
#    	ddd = rbind(ddd,ddd2)
#    	ddd$Welle = factor(ddd$Welle)
#    	ddd$xvar = scale(ddd$xvar) + rnorm(nrow(ddd),sd=0.1)
#    	ddd$yvar = scale(ddd$yvar) + rnorm(nrow(ddd),sd=0.1)
#    	multi = lmer(yvar ~ xvar + (1|id), data=ddd) # to get mean slope and intercept
#    	meaneff = fixef(multi)
#    	ddd = ddd[which(ddd$id==id),]
#    	stopifnot(!all(is.na(ddd$xvar)),!all(is.na(ddd$yvar)))
#    
#    	
#    	ggplot(ddd,aes(x=xvar,yvar)) + 
#    		geom_point(aes(colour=Welle)) + 
#    		scale_colour_manual(values=cbPalette)+
#    		geom_smooth(method="lm",colour=wave2colour,se=F) + 
#    		geom_abline(linetype=2,intercept=meaneff[1] , slope=meaneff[2]) + 
#    		geom_abline(linetype=2,intercept=meaneff[1] , slope=meaneff[2]) + 
#    		scale_x_continuous(xlab,breaks=c(-2,-1,0,1,2),labels=c('--','-','ø','+','++')) + 
#    		scale_y_continuous(ylab,breaks=c(-2,-1,0,1,2),labels=c('--','-','ø','+','++')) +
#    		labs(title=title)
#    }, error = function(e) {stop(simpleError('Dieser Wert fehlt bei Ihnen.'))})
#    }
#    
#    plot_both_waves = function(variable,title, xlab, ylab = 'Häufigkeit dieses Wertes in unserer Stichprobe',standardize=T,binwidth=0.25) {
#    	tryCatch({
#    	variable2 = paste0(variable,'_w2')
#    	if(standardize) {
#    	stand = scale(si[,variable])[,1]
#    	stand2 = scale(si[,variable2])[,1]
#    	} else 
#    	{
#    		stand = si[,variable]
#    		stand2 = si[,variable2]
#    	}
#    	zvalue = stand[which(si$id==id)]
#    	zvalue2 = stand2[which(si$id==id)]
#    	stopifnot(!is.na(zvalue),!is.na(zvalue))
#    	if(!is.na(zvalue)) {
#        
#    		if(standardize) {
#        if(zvalue>3) zvalue=3
#        if(zvalue<(-3)) zvalue=-3  
#        if(zvalue2>3) zvalue2=3
#        if(zvalue2<(-3)) zvalue2=-3  
#    		stand = stand + rnorm(length(stand),sd=0.2)
#    		stand2 = stand2 + rnorm(length(stand),sd=0.2)
#    
#        }
#    
#    	normal = data.frame(werte = seq(-3,3,length=2000),haeufigkeit = dnorm(seq(-3,3,length=2000),mean=0, sd=1))
#    		plot=
#    			ggplot()+
#    			geom_bar(binwidth=binwidth,data=as.data.frame(stand),aes(x=stand,y=..density..),alpha=0.3,fill=wave1colour)+
#    			geom_bar(binwidth=binwidth,data=as.data.frame(stand2),aes(x=stand2,y=..density..),alpha=0.3,fill=wave2colour)+
#    			geom_vline(colour=wave1colour,xintercept=zvalue,size=1.2)+
#    			geom_vline(colour=wave2colour,xintercept=zvalue2,size=1.2)+
#    			xlab(xlab)+
#        	ylab(ylab)+
#    			labs(title=title) + 
#    			scale_y_continuous(breaks=c(0,0.1,0.2,0.3,0.4),labels=c('0%','10%','20%','30%','40%'))
#    		if(standardize)
#    			{
#    				plot + geom_line(data=normal,aes(x=werte,y=haeufigkeit),alpha=0.8)+
#      scale_x_continuous(breaks=c(-2,-1,0,1,2),labels=c('stark unter-\ndurchschnitlich','unterdurch-\nschnittlich','Durchschnitt','überdurch-\nschnittlich','stark über-\ndurchschnittlich'))
#    
#    		} else {
#    			plot
#    		}
#    
#    	}
#    	}, error = function(e) {stop(simpleError('Dieser Wert fehlt bei Ihnen.'))})
#    }
#    
#    individual_scaled = function(var) {
#    	scale(as.numeric(si[,var]))[which(si$id==id),1]
#    }
#    plot_change = function(w1,w2,title, xlab, ylab = 'Häufigkeit dieses Wertes in unserer Stichprobe') {
#    	tryCatch({
#    	change = data.frame(Welle = 1:2, Wert=c(individual_scaled(w1),individual_scaled(w2)))
#    ggplot(change,aes(x=Welle,y=Wert))+
#            geom_line(size=2)+
#            xlab(xlab)+
#            ylab(ylab)+
#            scale_y_continuous(limits=c(-3,3),breaks=c(-2,-1,0,1,2),labels=c('stark\n unterdurchschnitlich','unterdurchschnittlich','Durchschnitt','überdurchschnittlich','stark\n überdurchschnittlich'))+
#            scale_x_continuous(breaks=c(1,2),labels=c('Welle 1','Welle 2'))+
#            labs(title=title)
#    		}, error = function(e) {stop(simpleError('Dieser Wert fehlt bei Ihnen.'))})
#    
#    }
#    change_scaled = function(w1,w2) {
#    	sim = melt(si[,c('id',w1,w2)])
#    	sim$value = scale(as.numeric(sim[,'value']))
#    	sim$Welle = ifelse(str_detect(sim$variable,"_w2$"),2,1)
#    	rbind(
#    		sim[which(sim$id==id),c('id','Welle','value')],
#    		ddply(sim[which(sim$id!=id),],"Welle",summarise,id=NA,value=mean(value,na.rm=T))
#    	)
#    }
#    
#    plot_change_compared = function(w1,w2,title, xlab, ylab = 'Häufigkeit dieses Wertes im Vergleich mit anderen Teilnehmern') {
#    	tryCatch({
#    	change = change_scaled(w1,w2)
#    	stopifnot(!is.na(change$value))
#    	change$Wer = factor(ifelse(is.na(change$id),'Andere','Sie'))
#    ggplot(change,aes(x=Welle,y=value,colour=Wer))+
#            geom_line(size=1)+
#    				scale_colour_manual(values=youthey)+
#            xlab(xlab)+
#            ylab(ylab)+
#            scale_y_continuous(limits=c(-3,3),breaks=c(-2,-1,0,1,2),labels=c('stark\n unterdurchschnitlich','unterdurchschnittlich','Durchschnitt','überdurchschnittlich','stark\n überdurchschnittlich'))+
#            scale_x_continuous(breaks=c(1,2),labels=c('Welle 1','Welle 2'))+
#            labs(title=title)
#    	}, error = function(e) {stop(simpleError('Dieser Wert fehlt bei Ihnen.'))})
#    }