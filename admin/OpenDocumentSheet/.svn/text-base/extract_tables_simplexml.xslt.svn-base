<?xml version="1.0" encoding="utf-8"?>
<!--
    extract_tables_simplexml.xslt - Extract tables from OpenDocument spreadsheet
    Copyright (C) 2006  Shih Yuncheng <shirock@educities.edu.tw>

    extract_tables.xslt - Extract tables from OpenDocument spreadsheet
    Copyright (C) 2006  J. David Eisenberg

    This library is free software; you can redistribute it and/or
    modify it under the terms of the GNU Lesser General Public
    License as published by the Free Software Foundation; either
    version 2.1 of the License, or (at your option) any later version.

    This library is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
    Lesser General Public License for more details.

    You should have received a copy of the GNU Lesser General Public
    License along with this library; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
-->

<xsl:stylesheet version="1.0"
	xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
	xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"
	xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0"
	xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"
	xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0"
	xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0"
	xmlns:number="urn:oasis:names:tc:opendocument:xmlns:datastyle:1.0"
	xmlns:ooo="http://openoffice.org/2004/office"
	xmlns:ooow="http://openoffice.org/2004/writer"
	xmlns:oooc="http://openoffice.org/2004/calc"
	xmlns="http://www.w3.org/1999/xhtml"
	exclude-result-prefixes="office style text table fo number ooo ooow oooc #default"
>

<xsl:output
	method="xml"
	indent="yes"
    encoding="utf-8"
	omit-xml-declaration="no"
/>

<xsl:template match="/office:document-content/office:body/office:spreadsheet">
<workbook>
	<xsl:apply-templates select="table:table"/>
</workbook>
</xsl:template>

<xsl:template match="table:table">
<sheet>
    <name><xsl:value-of select="@table:name"/></name>
	<xsl:apply-templates select="table:table-row">
		<xsl:with-param name="type" select="'col'"/>
	</xsl:apply-templates>
</sheet>
</xsl:template>

<!-- REMOVE THIS:only output tables which have text in their first cell -->
<xsl:template match="table:table-row">
	<xsl:param name="type"/>
	<!--xsl:if test="table:table-cell[1]/text:p"-->
		<row>
			<xsl:apply-templates select="table:table-cell">
				<xsl:with-param name="type" select="$type"/>
			</xsl:apply-templates>
		</row>
	<!--/xsl:if-->
</xsl:template>

<xsl:template match="table:table-cell">
	<xsl:param name="type"/>
	<xsl:variable name="count">
	<xsl:choose>
		<xsl:when test="@table:number-columns-repeated">
			<xsl:value-of select="@table:number-columns-repeated"/>
		</xsl:when>
		<xsl:otherwise>1</xsl:otherwise>
	</xsl:choose>
	</xsl:variable>
	
	<!-- Empty last cells should be ignored -->
	<xsl:if test="position() != last() or count(child::*) &gt; 0">
		<xsl:call-template name="process-cell">
			<xsl:with-param name="type" select="$type"/>
			<xsl:with-param name="count" select="$count"/>
		</xsl:call-template>
	</xsl:if>
</xsl:template>

<xsl:template name="process-cell">
	<xsl:param name="type"/>
	<xsl:param name="count"/>
	<xsl:element name="{$type}">
		<xsl:if test="@office:value-type = 'float'">
			<xsl:attribute name="dataType">float</xsl:attribute>
		</xsl:if>
		<xsl:value-of select="text:p"/>
	</xsl:element>
	<xsl:if test="$count &gt; 1">
		<xsl:call-template name="process-cell">
			<xsl:with-param name="type" select="$type"/>
			<xsl:with-param name="count" select="$count - 1"/>
		</xsl:call-template>
	</xsl:if>
</xsl:template>

<xsl:template match="text()"/>

</xsl:stylesheet>
