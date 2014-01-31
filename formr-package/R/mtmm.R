## function for rendering a multi trait multi method matrix
mtmm = function (
	variables, # data frame of variables that are supposed to be correlated
	reliabilities = NULL, # reliabilties: column 1: scale, column 2: rel. coefficient
	regex = "^([a-z][a-z][a-z])((\\.[a-z0-9][a-z0-9][a-z0-9])?([A-Z0-9_][A-Z0-9_][A-Z0-9_])?_(x|r|d|c|z|s|l|p|t|i)(_w2)?)$", # regular expression to separate construct and method from the variable name. the first two matched groups are chosen
	cors = NULL,
	construct = 2,
	method = 3
	) {
	library(stringr); library(Hmisc)
	
	if(is.null(cors)) 
		cors = cor(variables, use="pairwise.complete.obs") # select variables
	
	var.names = colnames(cors)
	#cors2 = rcorr(as.matrix(multitraitmultimethod))
	#print(qplot(x=as.vector(cors2$P),y=abs(as.vector(cors2$r)) ))
	library(reshape2)
	corm = melt(cors)
	corm = corm[ corm[,'Var1']!=corm[,'Var2'] , ] # substitute the 1s with the scale reliabilities here
	if(!is.null(reliabilities)) {
		rel = reliabilities
		names(rel) = c('Var1','value')
		rel$Var2 = rel$Var1
		rel = rel[which(rel$Var1 %in% var.names), c('Var1','Var2','value')]
		corm = rbind(corm,rel)
	}
	if(any(is.na(str_match(corm$Var1,regex)[,c(construct,method)]))) 
	{
		print(unique(str_match(corm$Var1,regex)[,c(0,construct,method)]))
		stop ("regex broken")
	}
	corm[, c('trait_X','method_X')] = str_match(corm$Var1,regex)[,c(construct,method)]  # regex matching our column naming schema to extract trait and method
	corm[, c('trait_Y','method_Y')] = str_match(corm$Var2,regex)[,c(construct,method)] 
	
	corm[,c('var1.s','var2.s')] <- t(apply(corm[,c('Var1','Var2')], 1, sort)) # sort pairs to find dupes
	corm[which(
		corm[ ,'trait_X']==corm[,'trait_Y'] 
		& corm[,'method_X']!=corm[,'method_Y']),'type'] = 'monotrait-heteromethod (validity)'
	corm[which(
		corm[ ,'trait_X']!=corm[,'trait_Y'] 
		& corm[,'method_X']==corm[,'method_Y']), 'type'] = 'heterotrait-monomethod'
	corm[which(
		corm[ ,'trait_X']!=corm[,'trait_Y'] 
		& corm[,'method_X']!=corm[,'method_Y']), 'type'] = 'heterotrait-heteromethod'
	corm[which( 
		corm[, 'trait_X']==corm[,'trait_Y'] 
		& corm[,'method_X']==corm[,'method_Y']), 'type'] = 'monotrait-monomethod (reliability)'
	
	corm$trait_X = factor(corm$trait_X)
	corm$trait_Y = factor(corm$trait_Y,levels=rev(levels(corm$trait_X)))
	corm$method_X = factor(corm$method_X)
	corm$method_Y = factor(corm$method_Y,levels=levels(corm$method_X))
	corm = corm[order(corm$method_X,corm$trait_X),]
	corm = corm[!duplicated(corm[,c('var1.s','var2.s')]), ] # remove dupe pairs
	
	#building ggplot
	mtmm_plot <- ggplot(data= corm) + # the melted correlation matrix
		layer(geom = 'tile', mapping = aes(x = trait_X, y = trait_Y, fill = type)) + 
		#layer(geom = 'raster', mapping = aes(x = trait_X, y = trait_Y,  fill = abs(value))) + # the tiles (raster is faster, tiles are the same size)
		layer(geom = 'text', mapping = aes(x = trait_X, y = trait_Y, label = str_replace(round(value,2),"0\\.", ".") ,size=log(value^2))) + # the correlation text
		facet_grid(method_Y ~ method_X) + 
		theme_bw() + 
		theme(panel.background = element_rect(colour = NA), 
					panel.grid.minor = element_blank(), 
					axis.line = element_line(), 
					strip.background = element_blank(),
					panel.grid = element_blank()
					
		) + 
		scale_fill_brewer('Type') +
		scale_size("Absolute size",guide=F) +
		scale_colour_gradient(guide=F)
	
	mtmm_plot
}
# data.mtmm = data.frame(
#	'ach.self_report' = rnorm(200),'pow.self_report'= rnorm(200),'aff.self_report'= rnorm(200),
#	'ach.peer_report' = rnorm(200),'pow.peer_report'= rnorm(200),'aff.peer_report'= rnorm(200),
#	'ach.diary' = rnorm(200),'pow.diary'= rnorm(200),'aff.diary'= rnorm(200))
# reliabilities = data.frame(scale = names(data.mtmm), rel = runif(length(names(data.mtmm))))
# mtmm(data.mtmm, reliabilities = reliabilities, regex="^(.+)\\.(.+)$")