local p = {}

local function index_for_field( tab, name )
	local index = -1
	for i, field in ipairs(tab.schema.fields) do
		if field.name == name then
			index = i
		end
	end
	return index
end

local function empty_field( field )
	-- Ideally we'd return nil to indicate an empty data point.
	-- Currently this doesn't round-trip back to JSON well, as
	-- Lua doesn't like doing array-style tables with nil contents.
	if field.type == "number" then
		return 0
	elseif field.type == "localized" then
		return { ["en"] = "" }
	else
		return ""
	end
end

function p.identity( tab )
	return tab
end

function p.field_equals( tab, args )
	columnName = args[1]
	value = args[2]
	local index = index_for_field( tab, columnName )
	local out = {}
	if index > 0 then
		for i, row in ipairs(tab.data) do
			if row[index] == value then
				table.insert(out, row)
			end
		end
	end
	tab.data = out

	return tab
end

function p.select_columns( tab, args )
	local columnNames = args

	local keepColumn = {}
	local fields = {}
	for _, columnName in ipairs(columnNames) do
		local i = index_for_field( tab, columnName )
		if i > 0 then
			keepColumn[i] = true
			table.insert( fields, tab.schema.fields[i] );
		end
	end
	tab.schema.fields = fields

	local out = {}
	for i, row in ipairs(tab.data) do
		local rowOut = {}
		for j, value in ipairs(row) do
			if keepColumn[j] then
				table.insert(rowOut, value)
			end
		end
		table.insert(out, rowOut)
	end
	tab.data = out

	return tab
end

function p.sum_columns( tab, args )
	local columnNames = mw.text.split( args.columns or "", ",", true )
	local sum_name = args.field or "sum"
	local sum_title = {}
	for key, val in pairs( args ) do
		local bits = mw.text.split( key, ":", true )
		local base = bits[1]
		local lang = bits[2]
		if base == "title" and lang then
			sum_title[lang] = val
		end
	end

	local includeColumn = {}
	local fieldType = {}
	for _, columnName in ipairs(columnNames) do
		local i = index_for_field( tab, columnName )
		if i > 0 then
			includeColumn[i] = true
		end
	end
	table.insert(tab.schema.fields, {
		name=sum_name,
		title=sum_title,
		type="number"
	})

	local out = {}
	for i, row in ipairs(tab.data) do
		local sum = 0.0
		for j, value in ipairs(row) do
			if includeColumn[j] then
				sum = sum + tonumber(value)
			end
		end
		table.insert(row, sum)
	end
	return tab
end

function p.prepend( tab, args )
	local tab2Name = args[1]
	local tab2 = mw.ext.data.get( tab2Name, '_' )

	-- map the fields
	local index2Map = {}
	for index, field in ipairs( tab.schema.fields ) do
		index2Map[index] = index_for_field( tab2, field.name )
	end

	-- move prepended over
	local data = {}
	for _, row2 in ipairs(tab2.data) do
		local row = {}
		for index, field in ipairs(tab.schema.fields) do
			local index2 = index2Map[index]
			if index2 > 0 then
				table.insert(row, row2[index2])
			else
				table.insert(row, empty_field( field ))
			end
		end
		table.insert(data, row)
	end

	-- move own data over
	for _, row in ipairs(tab.data) do
		table.insert(data, row)
	end

	tab.data = data
	return tab
end

function p.double( tab, args )
	-- doubles all numeric values in-place
	for _, row in ipairs( tab.data ) do
		for i, val in ipairs( row ) do
			if tab.schema.fields[i].type == "number" then
				row[i] = val * 2
			end
		end
	end
	return tab
end

return p
